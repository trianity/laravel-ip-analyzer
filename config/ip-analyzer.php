<?php

declare(strict_types=1);

return [
    'databases' => [
        'country' => env('IP_ANALYZER_COUNTRY_DB', storage_path('app/ip-analyzer/GeoLite2-Country.mmdb')),
        'asn' => env('IP_ANALYZER_ASN_DB', storage_path('app/ip-analyzer/GeoLite2-ASN.mmdb')),
    ],
    // Compare with MMDB build_epoch, not file modification time.
    // This is an operational warning threshold, not a licensing guarantee.
    'max_age_days' => 30,
    // These proposed options are implemented by the TDD tasks, not this skeleton.
    'rules' => [
        'ip_cidr' => [],
        'asn' => [],
        'country' => [],
    ],
    // Ordered list of Rule class names, resolved through the Laravel container.
    'custom_rules' => [],
];
