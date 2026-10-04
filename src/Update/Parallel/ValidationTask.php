<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update\Parallel;

final readonly class ValidationTask
{
    public function __construct(
        public string $id,
        public string $database,
        public string $path,
        public bool $requireEdition,
    ) {}
}
