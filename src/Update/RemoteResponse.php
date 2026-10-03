<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update;

final readonly class RemoteResponse
{
    /** @param array<string, mixed> $state */
    public function matches(array $state): bool
    {
        if ($this->etagHash !== null && is_string($state['etag_hash'] ?? null)) {
            return $this->etagHash === $state['etag_hash']
                && ($this->lastModified === null || ! is_int($state['last_modified'] ?? null) || $this->lastModified === $state['last_modified']);
        }
        if ($this->lastModified !== null && is_int($state['last_modified'] ?? null)) {
            return $this->lastModified === $state['last_modified'];
        }

        return $this->validator !== null && ($state['validator'] ?? null) === $this->validator;
    }

    public function __construct(public int $status, public ?string $validator = null, public ?int $retryAt = null, public ?string $etagHash = null, public ?int $lastModified = null) {}
}
