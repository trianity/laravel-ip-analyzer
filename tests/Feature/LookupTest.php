<?php

use Trianity\IpAnalyzer\Contracts\IpLookup;
use Trianity\IpAnalyzer\Enums\LookupStatus;

beforeEach(function () {
    config(['ip-analyzer.databases.country' => __DIR__.'/../Fixtures/country.mmdb',
        'ip-analyzer.databases.asn' => __DIR__.'/../Fixtures/asn.mmdb']);
});

it('reads independent facts with the real local SDK', function (string $ip) {
    $facts = app(IpLookup::class)->lookup($ip);
    expect($facts->countryStatus)->toBe(LookupStatus::Found)
        ->and($facts->countryCode)->toBe('HU')
        ->and($facts->asnStatus)->toBe(LookupStatus::Found)
        ->and($facts->asn)->toBe(64512)
        ->and($facts->asnOrganization)->toBe('Synthetic Network')
        ->and($facts->countryDatabase->databaseType)->toBe('GeoLite2-Country')
        ->and($facts->countryDatabase->stale)->toBeTrue();
})->with(['8.8.8.8', '2606:4700:4700::1111']);

it('reports missing records without defaults', function () {
    $facts = app(IpLookup::class)->lookup('1.1.1.1');
    expect($facts->countryStatus)->toBe(LookupStatus::NotFound)
        ->and($facts->asnStatus)->toBe(LookupStatus::NotFound)
        ->and($facts->countryCode)->toBeNull()->and($facts->asn)->toBeNull();
});

it('keeps each source independent', function (string $source, string $file, string $error) {
    config(["ip-analyzer.databases.$source" => __DIR__.'/../Fixtures/'.$file]);
    $facts = app(IpLookup::class)->lookup('8.8.8.8');
    $other = $source === 'country' ? 'asn' : 'country';
    expect($facts->{$source.'Status'})->toBe(LookupStatus::Unavailable)
        ->and($facts->{$source.'ErrorCode'})->toBe($error)
        ->and($facts->{$other.'Status'})->toBe(LookupStatus::Found);
})->with([
    ['country', 'absent.mmdb', 'file_unreadable'],
    ['asn', 'absent.mmdb', 'file_unreadable'],
    ['country', 'README.md', 'invalid_database'],
    ['asn', 'README.md', 'invalid_database'],
    ['country', 'asn.mmdb', 'database_type_mismatch'],
    ['asn', 'country.mmdb', 'database_type_mismatch'],
    ['country', 'wrong-type.mmdb', 'database_type_mismatch'],
]);

it('never substitutes registered country', function () {
    config(['ip-analyzer.databases.country' => __DIR__.'/../Fixtures/registered-only.mmdb']);
    $facts = app(IpLookup::class)->lookup('8.8.8.8');
    expect($facts->countryCode)->toBeNull()->and($facts->countryStatus)->toBe(LookupStatus::NotFound);
});

it('rejects invalid age configuration', function ($age) {
    config(['ip-analyzer.max_age_days' => $age]);
    expect(fn () => app(IpLookup::class)->lookup('8.8.8.8'))->toThrow(InvalidArgumentException::class);
})->with([-1, '30', 1.5, null]);

it('observes atomic replacement in a long lived lookup', function () {
    $path = tempnam(sys_get_temp_dir(), 'ip-db-');
    $next = $path.'.next';
    try {
        copy(__DIR__.'/../Fixtures/country.mmdb', $path);
        config(['ip-analyzer.databases.country' => $path]);
        $lookup = app(IpLookup::class);
        expect($lookup->lookup('8.8.8.8')->countryCode)->toBe('HU');
        copy(__DIR__.'/../Fixtures/country-next.mmdb', $next);
        rename($next, $path);
        expect($lookup->lookup('8.8.8.8')->countryCode)->toBe('DE');
    } finally {
        @unlink($path);
        @unlink($next);
    }
});

it('rejects invalid configuration even for non-public input', function () {
    config(['ip-analyzer.max_age_days' => -1]);
    expect(fn () => app(IpLookup::class)->lookup('127.0.0.1'))->toThrow(InvalidArgumentException::class);
});

it('reports unreadable files without exposing paths', function () {
    $path = tempnam(sys_get_temp_dir(), 'unreadable-');
    try {
        copy(__DIR__.'/../Fixtures/country.mmdb', $path);
        chmod($path, 0000);
        config(['ip-analyzer.databases.country' => $path]);
        $facts = app(IpLookup::class)->lookup('8.8.8.8');
        expect($facts->countryErrorCode)->toBe('file_unreadable')
            ->and(json_encode($facts))->not->toContain($path)
            ->and($facts->asnStatus)->toBe(LookupStatus::Found);
    } finally {
        chmod($path, 0600);
        unlink($path);
    }
});
