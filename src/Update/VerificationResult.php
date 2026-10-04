<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update;

final readonly class VerificationResult
{
    public function __construct(
        public string $database,
        public string $status,
        public ?int $buildEpoch = null,
        public ?string $sha256 = null,
        public ?string $errorCode = null,
        public bool $integrityVerified = false,
    ) {}
}
