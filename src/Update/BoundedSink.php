<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

final class BoundedSink implements StreamInterface
{
    use StreamDecoratorTrait;

    public int $bytes = 0;

    public ?UpdateFailure $failure = null;

    public bool $accept = false;

    public function __construct(?string $path, private readonly int $limit)
    {
        $resource = $path === null ? null : @fopen($path, 'wb');
        if ($resource === false) {
            throw new UpdateFailure('disk_error');
        }
        $this->stream = Utils::streamFor($resource ?? '');
    }

    public function write(#[\SensitiveParameter] string $string): int
    {
        $length = strlen($string);
        $this->bytes += $length;
        if ($this->bytes > $this->limit) {
            throw $this->failure = new UpdateFailure('download_too_large');
        }
        if (! $this->accept) {
            return $length;
        }
        try {
            $written = $this->stream->write($string);
            if ($written !== $length) {
                throw new \RuntimeException;
            }

            return $written;
        } catch (\Throwable) {
            throw $this->failure = new UpdateFailure('disk_error');
        }
    }
}
