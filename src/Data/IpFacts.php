<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Data;

use Trianity\IpAnalyzer\Enums\LookupStatus;

final readonly class IpFacts
{
    public function __construct(
        public string $ip,
        public LookupStatus $countryStatus,
        public LookupStatus $asnStatus,
        public ?string $countryCode = null,
        public ?int $asn = null,
        public ?string $asnOrganization = null,
        public ?DatabaseMetadata $countryDatabase = null,
        public ?DatabaseMetadata $asnDatabase = null,
        public ?string $countryErrorCode = null,
        public ?string $asnErrorCode = null,
    ) {}
}
