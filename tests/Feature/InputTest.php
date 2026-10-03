<?php

use Trianity\IpAnalyzer\Contracts\IpLookup;
use Trianity\IpAnalyzer\Enums\LookupStatus;

it('rejects non-address input without opening databases', function (string $ip) {
    $facts = app(IpLookup::class)->lookup($ip);
    expect($facts->countryStatus)->toBe(LookupStatus::InvalidInput)
        ->and($facts->asnStatus)->toBe(LookupStatus::InvalidInput);
})->with(['', 'example.com', 'https://1.1.1.1', '1.1.1.1:80', '1.1.1.1, 8.8.8.8', ' 1.1.1.1', 'fe80::1%eth0']);

it('does not read databases for special-use addresses', function (string $ip) {
    $facts = app(IpLookup::class)->lookup($ip);
    expect($facts->countryStatus)->toBe(LookupStatus::NonPublic)
        ->and($facts->asnStatus)->toBe(LookupStatus::NonPublic);
})->with(['0.1.2.3', '10.0.0.1', '100.64.0.1', '127.0.0.1', '169.254.1.1', '172.16.0.1', '192.168.1.1', '192.0.0.9', '192.0.2.1', '192.88.99.1', '198.18.0.1', '198.51.100.1', '203.0.113.1', '224.0.0.1', '255.255.255.255', '::', '::1', '::ffff:127.0.0.1', '64:ff9b::808:808', '100::1', '2001:db8::1', '2001::1', '2002::1', '3fff::1', 'fc00::1', 'fe80::1', 'ff02::1']);

it('canonicalizes mapped IPv4 and IPv6', function () {
    expect(app(IpLookup::class)->lookup('::ffff:8.8.8.8')->ip)->toBe('8.8.8.8')
        ->and(app(IpLookup::class)->lookup('2606:4700:4700:0:0:0:0:1111')->ip)->toBe('2606:4700:4700::1111');
});

it('also excludes special-purpose globally reachable service prefixes', function ($ip) {
    expect(app(IpLookup::class)->lookup($ip)->countryStatus)->toBe(LookupStatus::NonPublic);
})->with(['192.31.196.1', '192.52.193.1', '192.175.48.1', '2620:4f:8000::1']);
