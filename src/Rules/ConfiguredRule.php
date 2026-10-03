<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Rules;

use InvalidArgumentException;
use Trianity\IpAnalyzer\Contracts\Rule;
use Trianity\IpAnalyzer\Data\IpFacts;
use Trianity\IpAnalyzer\Data\RuleMatch;
use Trianity\IpAnalyzer\Enums\LookupStatus;
use Trianity\IpAnalyzer\Enums\Severity;
use Trianity\IpAnalyzer\Support\IpAddress;

final readonly class ConfiguredRule implements Rule
{
    private RuleMatch $match;

    private string|int $value;

    private ?int $prefix;

    public function __construct(private string $group, mixed $entry)
    {
        if (! is_array($entry) || array_diff(array_keys($entry), ['id', 'value', 'reason_code', 'severity', 'message']) !== []) {
            throw new InvalidArgumentException('Invalid rule entry.');
        }
        foreach (['id', 'reason_code', 'severity', 'message'] as $field) {
            if (! isset($entry[$field]) || ! is_string($entry[$field]) || trim($entry[$field]) === '') {
                throw new InvalidArgumentException('Rule fields must be non-empty strings.');
            }
        }
        $severity = Severity::tryFrom($entry['severity']);
        if ($severity === null) {
            throw new InvalidArgumentException('Invalid rule severity.');
        }
        $this->match = new RuleMatch($entry['id'], $entry['reason_code'], $severity, $entry['message']);
        $value = $entry['value'] ?? null;
        $prefix = null;
        if ($group === 'asn') {
            if (! is_int($value) || $value <= 0) {
                throw new InvalidArgumentException('ASN must be a positive integer.');
            }
        } elseif ($group === 'country') {
            if (! is_string($value) || preg_match('/^[a-zA-Z]{2}$/D', $value) !== 1) {
                throw new InvalidArgumentException('Country must be a two-letter code.');
            }
            $value = strtoupper($value);
        } elseif ($group === 'ip_cidr') {
            if (! is_string($value)) {
                throw new InvalidArgumentException('IP rule must be a string.');
            }
            $parts = explode('/', $value);
            $address = IpAddress::normalize($parts[0]);
            // Mapped single addresses normalize to IPv4; mapped CIDRs are ambiguous.
            if ($address === null || count($parts) > 2 || (isset($parts[1]) && str_contains($parts[0], ':') && ! str_contains($address, ':'))) {
                throw new InvalidArgumentException('Invalid IP/CIDR rule.');
            }
            $max = str_contains($address, ':') ? 128 : 32;
            if (isset($parts[1]) && (preg_match('/^(0|[1-9][0-9]*)$/D', $parts[1]) !== 1 || (int) $parts[1] > $max)) {
                throw new InvalidArgumentException('Invalid CIDR prefix.');
            }
            $prefix = isset($parts[1]) ? (int) $parts[1] : $max;
            $value = $address;
        } else {
            throw new InvalidArgumentException('Unknown rule group.');
        }
        $this->value = $value;
        $this->prefix = $prefix;
    }

    public function id(): string
    {
        return $this->match->ruleId;
    }

    public function evaluate(IpFacts $facts): ?RuleMatch
    {
        $matched = match ($this->group) {
            default => throw new \LogicException('Unexpected rule group.'),
            'asn' => $facts->asnStatus === LookupStatus::Found && $facts->asn === $this->value,
            'country' => $facts->countryStatus === LookupStatus::Found && $facts->countryCode === $this->value,
            'ip_cidr' => ($ip = IpAddress::normalize($facts->ip)) !== null && IpAddress::matches($ip, $this->value, $this->prefix),
        };

        return $matched ? $this->match : null;
    }
}
