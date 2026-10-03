<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update;

final readonly class UpdateResult
{
    public function __construct(
        public string $database,
        public string $status,
        public ?int $oldBuildEpoch = null,
        public ?int $newBuildEpoch = null,
        public ?string $errorCode = null,
        public ?string $warning = null,
        public ?int $nextRetryAt = null,
        public bool $installed = false,
    ) {}
}
