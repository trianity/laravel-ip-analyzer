<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update;

final readonly class FreshnessDecision
{
    public function __construct(public string $status) {}

    public function replacementRequired(): bool
    {
        return $this->status !== 'up_to_date';
    }
}
