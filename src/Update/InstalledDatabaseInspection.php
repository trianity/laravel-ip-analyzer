<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update;

final readonly class InstalledDatabaseInspection
{
    public function __construct(
        public string $database,
        public string $status,
        public ?int $buildEpoch = null,
        public ?string $errorCode = null,
    ) {}
}
