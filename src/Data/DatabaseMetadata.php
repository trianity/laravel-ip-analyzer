<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Data;

final readonly class DatabaseMetadata
{
    public function __construct(
        public string $databaseType,
        public int $buildEpoch,
        public bool $stale,
    ) {}
}
