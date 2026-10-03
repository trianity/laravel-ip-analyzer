<?php

declare(strict_types=1);

namespace App\IpRules;

use Trianity\IpAnalyzer\Contracts\Rule;
use Trianity\IpAnalyzer\Data\IpFacts;
use Trianity\IpAnalyzer\Data\RuleMatch;
use Trianity\IpAnalyzer\Enums\LookupStatus;
use Trianity\IpAnalyzer\Enums\Severity;

/** Example only. ASN 64512 is documentation/test data, not a real blacklist. */
final class ObservedNetworkRule implements Rule
{
    public function id(): string
    {
        return 'observed-network';
    }

    public function evaluate(IpFacts $facts): ?RuleMatch
    {
        if ($facts->asnStatus !== LookupStatus::Found || $facts->asn !== 64512) {
            return null;
        }

        return new RuleMatch(
            ruleId: $this->id(),
            reasonCode: 'observed_abuse_network',
            severity: Severity::Warning,
            message: 'This network matches an application-maintained observation.',
        );
    }
}
