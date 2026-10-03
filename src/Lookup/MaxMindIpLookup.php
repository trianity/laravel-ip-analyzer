<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Lookup;

use Trianity\IpAnalyzer\Contracts\IpLookup;
use Trianity\IpAnalyzer\Data\IpFacts;
use Trianity\IpAnalyzer\Enums\LookupStatus;
use Trianity\IpAnalyzer\Support\IpAddress;

final class MaxMindIpLookup implements IpLookup
{
    public function __construct(private readonly LocalDatabase $databases) {}

    public function lookup(string $ip): IpFacts
    {
        $this->databases->validateConfiguration();
        $normalized = IpAddress::normalize($ip);
        $status = $normalized === null ? LookupStatus::InvalidInput
            : (IpAddress::isPublic($normalized) ? LookupStatus::Unavailable : LookupStatus::NonPublic);
        if ($status !== LookupStatus::Unavailable) {
            return new IpFacts($normalized ?? $ip, $status, $status);
        }
        $country = $this->databases->read('country', $normalized);
        $asn = $this->databases->read('asn', $normalized);

        return new IpFacts($normalized, $country->status, $asn->status,
            $country->countryCode, $asn->asn, $asn->asnOrganization,
            $country->metadata, $asn->metadata, $country->errorCode, $asn->errorCode);
    }
}
