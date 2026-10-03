<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Psr\Http\Message\StreamInterface;
use Throwable;
use Trianity\IpAnalyzer\Update\Progress\Interrupted;
use Trianity\IpAnalyzer\Update\Progress\Progress;

final class GuzzleTransport implements Transport
{
    /** @param HandlerStack<callable>|null $handler */
    public function __construct(private readonly ?HandlerStack $handler = null, private readonly Progress $progress = new Progress) {}

    public function request(string $method, #[\SensitiveParameter] string $url, #[\SensitiveParameter] UpdateOptions $options, ?string $sink = null): RemoteResponse
    {
        try {
            return $this->perform($method, $url, $options, $sink);
        } catch (Interrupted) {
            throw new Interrupted;
        } catch (UpdateFailure $e) {
            // Rebuild at the public boundary to discard internal callback arguments.
            throw new UpdateFailure($e->errorCode, $e->retryAt);
        } catch (Throwable) {
            $this->progress->checkpoint();
            // Never retain the request exception as "previous": URLs and auth are secrets.
            throw new UpdateFailure('transport_failed');
        }
    }

    private function perform(string $method, #[\SensitiveParameter] string $url, #[\SensitiveParameter] UpdateOptions $options, ?string $sink): RemoteResponse
    {
        if (! in_array($method, ['HEAD', 'GET'], true)) {
            throw new UpdateFailure('invalid_method');
        }
        $client = new Client(['handler' => $this->handler ?? HandlerStack::create(new CurlHandler)]);
        $visited = [];
        $started = hrtime(true);
        for ($hop = 0; ; $hop++) {
            $this->progress->checkpoint();
            $this->validateUrl($url, $options);
            if (isset($visited[$url]) || $hop > $options->integer('max_redirects')) {
                throw new UpdateFailure('redirect_limit');
            }
            $visited[$url] = true;
            $remaining = $options->integer('timeout') - ((hrtime(true) - $started) / 1_000_000_000);
            if ($remaining <= 0) {
                throw new UpdateFailure('timeout');
            }
            $headers = ['Accept' => 'application/octet-stream', 'Accept-Encoding' => 'identity'];
            if ((new Uri($url))->getHost() === UpdateOptions::ORIGIN) {
                [$id, $key] = $options->credentials();
                $headers['Authorization'] = 'Basic '.base64_encode($id.':'.$key);
            }
            $download = new BoundedSink($method === 'GET' ? $sink : null, $options->integer('max_bytes'), $this->progress);
            try {
                $response = $client->send(new Request($method, $url, $headers), [
                    'allow_redirects' => false, 'http_errors' => false, 'verify' => true,
                    'cookies' => false, 'decode_content' => false,
                    'connect_timeout' => min($remaining, $options->integer('connect_timeout')),
                    'timeout' => $remaining, 'debug' => false, 'sink' => $download,
                    'progress' => function () use ($download): void {
                        $this->progress->checkpoint();
                        $this->progress->advance($download->bytes, $download->total);
                    },
                    'on_headers' => function ($response) use ($download, $method, $options): void {
                        $download->accept = $method === 'GET' && $response->getStatusCode() === 200;
                        if (! $download->accept) {
                            return;
                        }
                        $encoding = $response->getHeaderLine('Content-Encoding');
                        $length = $response->getHeaderLine('Content-Length');
                        if ($encoding !== '' && strtolower($encoding) !== 'identity') {
                            throw $download->failure = new UpdateFailure('unsupported_content_encoding');
                        }
                        if ($length !== '' && (! ctype_digit($length) || (float) $length > $options->integer('max_bytes'))) {
                            throw $download->failure = new UpdateFailure('download_too_large');
                        }
                        $download->total = $length === '' ? null : (int) $length;
                    },
                ]);
            } catch (Throwable) {
                $download->close();
                $this->progress->checkpoint();
                throw $download->failure ?? new UpdateFailure('transport_failed');
            }
            $body = $response->getBody();
            try {
                $status = $response->getStatusCode();
                if (in_array($status, [301, 302, 303, 307, 308], true)) {
                    $location = $response->getHeaderLine('Location');
                    if ($location === '') {
                        throw new UpdateFailure('missing_location');
                    }
                    // Validate raw userinfo before URI normalization can discard it.
                    if (preg_match('/[\x00-\x20\x7f\\\\]/', $location)) {
                        throw new UpdateFailure('unsafe_redirect');
                    }
                    $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));

                    continue;
                }
                $validator = $this->validator($response->getHeaderLine('ETag'), $response->getHeaderLine('Last-Modified'));
                $retry = trim($response->getHeaderLine('Retry-After'));
                $retryAt = null;
                if ($retry !== '') {
                    $retryAt = ctype_digit($retry) ? (strlen(ltrim($retry, '0')) < 18 ? time() + (int) $retry : PHP_INT_MAX) : $this->date($retry);
                }
                if ($method === 'GET' && $status === 200) {
                    if ($sink === null) {
                        throw new UpdateFailure('disk_error');
                    }
                    if ($response->getHeaderLine('Content-Encoding') !== '' && strtolower($response->getHeaderLine('Content-Encoding')) !== 'identity') {
                        throw new UpdateFailure('unsupported_content_encoding');
                    }
                    $length = $response->getHeaderLine('Content-Length');
                    if ($length !== '' && (! ctype_digit($length) || (float) $length > $options->integer('max_bytes'))) {
                        throw new UpdateFailure('download_too_large');
                    }
                    $download->total = $length === '' ? null : (int) $length;
                    // Mock handlers may return a body without applying the sink. Production
                    // CurlHandler has already streamed through BoundedSink during transfer.
                    if ($download->bytes === 0) {
                        while (! $body->eof()) {
                            if ((hrtime(true) - $started) / 1_000_000_000 > $options->integer('timeout')) {
                                throw new UpdateFailure('timeout');
                            }
                            $chunk = $this->readChunk($body);
                            if ($chunk === '' && ! $body->eof()) {
                                throw new UpdateFailure('interrupted_stream');
                            }
                            $download->write($chunk);
                        }
                    }
                    if ($download->bytes === 0 || ($length !== '' && $download->bytes !== (int) $length)) {
                        throw new UpdateFailure('interrupted_stream');
                    }
                }

                $this->progress->checkpoint();
                if ($method === 'GET' && $status === 200) {
                    $this->progress->advance($download->bytes, $download->total, force: true);
                }

                return new RemoteResponse($status, $validator, $retryAt, $this->etagHash($response->getHeaderLine('ETag')), $this->date($response->getHeaderLine('Last-Modified')));
            } finally {
                $body->close();
                $download->close();
            }
        }
    }

    /** @phpstan-impure */
    private function readChunk(StreamInterface $body): string
    {
        return $body->read(8192);
    }

    private function validateUrl(#[\SensitiveParameter] string $url, #[\SensitiveParameter] UpdateOptions $options): void
    {
        $parts = parse_url($url);
        if (! $parts || preg_match('/[\x00-\x20\x7f\\\\]/', $url) || ($parts['scheme'] ?? '') !== 'https'
            || ! in_array($parts['host'] ?? '', $options->hosts(), true) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new UpdateFailure('unsafe_redirect');
        }
    }

    private function validator(#[\SensitiveParameter] string $etag, #[\SensitiveParameter] string $modified): ?string
    {
        $tag = $this->etagHash($etag);
        $date = $this->date($modified);

        return $tag !== null || $date !== null ? hash('sha256', json_encode([$tag, $date], JSON_THROW_ON_ERROR)) : null;
    }

    private function etagHash(#[\SensitiveParameter] string $etag): ?string
    {
        return preg_match('/^(?:W\/)?"[\x21\x23-\x7e]{1,256}"$/D', $etag) === 1 ? hash('sha256', $etag) : null;
    }

    private function date(#[\SensitiveParameter] string $date): ?int
    {
        $parsed = DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \G\M\T', $date, new \DateTimeZone('UTC'));

        return $parsed && $parsed->format('D, d M Y H:i:s \G\M\T') === $date ? $parsed->getTimestamp() : null;
    }
}
