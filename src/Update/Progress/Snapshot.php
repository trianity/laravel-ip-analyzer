<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update\Progress;

final readonly class Snapshot
{
    public function __construct(
        public Phase $phase,
        public ?Database $database,
        public int $completed,
        public ?int $total,
        public float $elapsed,
        public ?float $eta,
        public ?int $waitSeconds = null,
        public bool $finished = false,
    ) {}
}
