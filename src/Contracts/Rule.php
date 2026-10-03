<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Contracts;

use Trianity\IpAnalyzer\Data\IpFacts;
use Trianity\IpAnalyzer\Data\RuleMatch;

interface Rule
{
    /** Stable, unique identifier, e.g. observed-network. */
    public function id(): string;

    /** Null means this rule did not match; it does not certify a safe IP. */
    public function evaluate(IpFacts $facts): ?RuleMatch;
}
