<?php

declare(strict_types=1);
use Trianity\IpAnalyzer\Update\UpdateOptions;

return [
    'databases' => [
        'country' => env('IP_ANALYZER_COUNTRY_DB', storage_path('app/ip-analyzer/GeoLite2-Country.mmdb')),
        'asn' => env('IP_ANALYZER_ASN_DB', storage_path('app/ip-analyzer/GeoLite2-ASN.mmdb')),
    ],
    // Compare with MMDB build_epoch, not file modification time.
    // This is an operational warning threshold, not a licensing guarantee.
    'max_age_days' => 30,
    'rules' => [
        'ip_cidr' => [],
        'asn' => [],
        'country' => [],
    ],
    // Ordered list of Rule class names, resolved through the Laravel container.
    'custom_rules' => [],
    'validation' => [
        'workers' => env('IP_ANALYZER_VALIDATION_WORKERS', 1),
        'worker_timeout' => env('IP_ANALYZER_VALIDATION_WORKER_TIMEOUT', 1800),
    ],
    // Only the explicit update command uses these credentials and network settings.
    'update' => [
        ...UpdateOptions::defaults(),
        'account_id' => env('IP_ANALYZER_MAXMIND_ACCOUNT_ID'),
        'license_key' => env('IP_ANALYZER_MAXMIND_LICENSE_KEY'),
        'schedule_enabled' => env('IP_ANALYZER_UPDATE_SCHEDULE', false),
    ],
];
