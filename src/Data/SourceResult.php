<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Data;

use Trianity\IpAnalyzer\Enums\LookupStatus;

final readonly class SourceResult
{
    public function __construct(
        public LookupStatus $status,
        public ?DatabaseMetadata $metadata = null,
        public ?string $errorCode = null,
        public ?string $countryCode = null,
        public ?int $asn = null,
        public ?string $asnOrganization = null,
    ) {}
}
