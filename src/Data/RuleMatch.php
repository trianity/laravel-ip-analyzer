<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Data;

use Trianity\IpAnalyzer\Enums\Severity;

final readonly class RuleMatch
{
    public function __construct(
        public string $ruleId,
        public string $reasonCode,
        public Severity $severity,
        public string $message,
    ) {}
}
