<?php

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Trianity\IpAnalyzer\Update\GuzzleTransport;
use Trianity\IpAnalyzer\Update\UpdateFailure;
use Trianity\IpAnalyzer\Update\UpdateOptions;

beforeEach(function () {
    config(['ip-analyzer.update.account_id' => '123456', 'ip-analyzer.update.license_key' => 'synthetic-secret']);
});

it('strips authentication and cookies at R2 using the real Guzzle middleware stack', function ($method) {
    $history = [];
    $stack = HandlerStack::create(new MockHandler([
        new Response(302, ['Location' => 'https://'.UpdateOptions::R2.'/file?signature=temporary-secret', 'Set-Cookie' => 'secret=cookie']),
        new Response(200, ['ETag' => '"version-one"'], $method === 'GET' ? 'archive' : ''),
    ]));
    $stack->push(Middleware::history($history));
    $sink = tempnam(sys_get_temp_dir(), 'transport-');
    try {
        $options = app(UpdateOptions::class);
        (new GuzzleTransport($stack))->request($method, $options->url('country'), $options, $method === 'GET' ? $sink : null);
        expect($history)->toHaveCount(2);
        expect($history[0]['request']->getHeaderLine('Authorization'))->toBe('Basic '.base64_encode('123456:synthetic-secret'));
        expect($history[1]['request']->hasHeader('Authorization'))->toBeFalse()
            ->and($history[1]['request']->hasHeader('Cookie'))->toBeFalse();
        foreach ($history as $entry) {
            expect($entry['options']['verify'])->toBeTrue()->and($entry['options']['allow_redirects'])->toBeFalse()
                ->and($entry['options']['decode_content'])->toBeFalse();
        }
    } finally {
        unlink($sink);
    }
})->with(['HEAD', 'GET']);

it('rejects unsafe redirects without leaking their URL', function ($location) {
    $stack = HandlerStack::create(new MockHandler([new Response(302, ['Location' => $location])]));
    $options = app(UpdateOptions::class);
    try {
        (new GuzzleTransport($stack))->request('HEAD', $options->url('country'), $options);
        test()->fail('Expected rejected redirect');
    } catch (UpdateFailure $e) {
        expect($e->errorCode)->toBe('unsafe_redirect')
            ->and((string) $e)->not->toContain('synthetic-secret', 'temporary-secret');
    }
})->with(['http://download.maxmind.com/file', 'https://evil.example/?signature=temporary-secret',
    'https://user:temporary-secret@download.maxmind.com/file', 'https://download.maxmind.com:444/file']);

it('follows relative locations and rejects loops or missing locations', function () {
    $options = app(UpdateOptions::class);
    $stack = HandlerStack::create(new MockHandler([new Response(302, ['Location' => '/next']), new Response(200)]));
    expect((new GuzzleTransport($stack))->request('HEAD', $options->url('country'), $options)->status)->toBe(200);
    foreach ([[], ['Location' => $options->url('country')]] as $headers) {
        $stack = HandlerStack::create(new MockHandler([new Response(302, $headers)]));
        expect(fn () => (new GuzzleTransport($stack))->request('HEAD', $options->url('country'), $options))->toThrow(UpdateFailure::class);
    }
});

it('limits actual streamed bytes even without Content-Length', function () {
    config(['ip-analyzer.update.max_bytes' => 3]);
    $options = app(UpdateOptions::class);
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], '1234')]));
    $sink = tempnam(sys_get_temp_dir(), 'transport-');
    try {
        expect(fn () => (new GuzzleTransport($stack))->request('GET', $options->url('country'), $options, $sink))->toThrow(UpdateFailure::class, 'download_too_large');
    } finally {
        unlink($sink);
    }
});

it('bounds bytes in the handler sink before the response is fully buffered', function () {
    config(['ip-analyzer.update.max_bytes' => 3]);
    $options = app(UpdateOptions::class);
    $stack = HandlerStack::create(function ($request, $requestOptions) {
        $response = new Response(200);
        $requestOptions['on_headers']($response);
        $requestOptions['sink']->write('1234');

        return Create::promiseFor($response);
    });
    $sink = tempnam(sys_get_temp_dir(), 'sink-');
    try {
        expect(fn () => (new GuzzleTransport($stack))->request('GET', $options->url('country'), $options, $sink))->toThrow(UpdateFailure::class, 'download_too_large');
    } finally {
        unlink($sink);
    }
});

it('sanitizes transport exceptions including trace and previous exception at debug verbosity', function () {
    $options = app(UpdateOptions::class);
    $stack = HandlerStack::create(new MockHandler([
        new Response(302, ['Location' => 'https://'.UpdateOptions::R2.'/file?signature=temporary-secret']),
        new ConnectException('synthetic-secret temporary-secret', new Request('HEAD', 'https://'.UpdateOptions::R2.'/?signature=temporary-secret')),
    ]));
    try {
        (new GuzzleTransport($stack))->request('HEAD', $options->url('country'), $options);
        test()->fail('Expected exception');
    } catch (UpdateFailure $exception) {
        expect((string) $exception)->not->toContain('temporary-secret', 'synthetic-secret')
            ->and($exception->getPrevious())->toBeNull();
    }
});

it('rejects bad transfer lengths encoding and redirect limits', function ($case) {
    config(['ip-analyzer.update.max_redirects' => 0]);
    $options = app(UpdateOptions::class);
    $response = match ($case) {
        'short' => new Response(200, ['Content-Length' => '100'], 'abc'),
        'invalid' => new Response(200, ['Content-Length' => 'invalid'], 'abc'),
        'encoding' => new Response(200, ['Content-Encoding' => 'gzip'], 'abc'),
        'redirect' => new Response(302, ['Location' => '/next']),
    };
    $stack = HandlerStack::create(new MockHandler([$response]));
    $path = tempnam(sys_get_temp_dir(), 'invalid-stream-');
    try {
        expect(fn () => (new GuzzleTransport($stack))->request('GET', $options->url('country'), $options, $path))->toThrow(UpdateFailure::class);
    } finally {
        unlink($path);
    }
})->with(['short', 'invalid', 'encoding', 'redirect']);

it('handles 401 403 and 429 HTML responses without parsing or exposing bodies', function ($status) {
    $stack = HandlerStack::create(new MockHandler([new Response($status, ['Retry-After' => '3600'], '<html>synthetic-secret temporary-secret</html>')]));
    $options = app(UpdateOptions::class);
    $result = (new GuzzleTransport($stack))->request('HEAD', $options->url('country'), $options);
    expect($result->status)->toBe($status)->and($result->retryAt)->toBeGreaterThanOrEqual(time() + 3599)
        ->and(json_encode($result))->not->toContain('synthetic-secret', 'temporary-secret');
})->with([401, 403, 429]);

it('hashes remote validators so opaque values never become persisted secrets', function () {
    $stack = HandlerStack::create(new MockHandler([
        new Response(200, ['ETag' => '"temporary-secret"', 'Last-Modified' => 'Wed, 15 Nov 2023 00:00:00 GMT']),
        new Response(200, ['Last-Modified' => 'invalid']),
    ]));
    $options = app(UpdateOptions::class);
    $transport = new GuzzleTransport($stack);
    $result = $transport->request('HEAD', $options->url('country'), $options);
    expect($result->validator)->toHaveLength(64)->and(json_encode($result))->not->toContain('temporary-secret');
    expect($transport->request('HEAD', $options->url('country'), $options)->validator)->toBeNull();
});

it('redacts oversized body chunks even when exception arguments are enabled', function () {
    $previous = ini_set('zend.exception_ignore_args', '0');
    $length = ini_set('zend.exception_string_param_max_len', '1024');
    config(['ip-analyzer.update.max_bytes' => 3]);
    $options = app(UpdateOptions::class);
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], 'LEAKKEY')]));
    $path = tempnam(sys_get_temp_dir(), 'body-secret-');
    try {
        (new GuzzleTransport($stack))->request('GET', $options->url('country'), $options, $path);
        test()->fail('Expected failure');
    } catch (UpdateFailure $exception) {
        expect((string) $exception)->not->toContain('LEAKKEY');
    } finally {
        ini_set('zend.exception_ignore_args', $previous);
        ini_set('zend.exception_string_param_max_len', $length);
        unlink($path);
    }
});
