<?php

use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;
use MaxMind\Db\Reader\InvalidDatabaseException;
use Trianity\IpAnalyzer\Contracts\IpLookup;
use Trianity\IpAnalyzer\Enums\LookupStatus;
use Trianity\IpAnalyzer\Lookup\LocalDatabase;
use Trianity\IpAnalyzer\Lookup\ReaderFactory;
use Trianity\IpAnalyzer\Support\Clock;

beforeEach(function () {
    config(['ip-analyzer.databases.country' => __DIR__.'/../Fixtures/country.mmdb',
        'ip-analyzer.databases.asn' => __DIR__.'/../Fixtures/asn.mmdb']);
});

it('computes exact age boundaries and exposes future builds', function ($now, $stale, $future, $age) {
    $clock = Mockery::mock(Clock::class);
    $clock->shouldReceive('now')->andReturn($now);
    app()->instance(Clock::class, $clock);
    $metadata = app(LocalDatabase::class)->read('country')->metadata;
    expect($metadata->stale)->toBe($stale)->and($metadata->futureBuild)->toBe($future)
        ->and($metadata->ageDays)->toEqualWithDelta($age, 0.000001);
})->with([
    [1700000000 + 30 * 86400, false, false, 30],
    [1700000000 + 30 * 86400 + 1, true, false, 30 + 1 / 86400],
    [1699999999, false, true, 0],
]);

it('reads documentation addresses only through the lower-level adapter', function () {
    expect(app(LocalDatabase::class)->read('country', '192.0.2.1')->countryCode)->toBe('HU')
        ->and(app(LocalDatabase::class)->read('asn', '2001:db8::1')->asn)->toBe(64512)
        ->and(app(IpLookup::class)->lookup('192.0.2.1')->countryStatus)->toBe(LookupStatus::NonPublic);
});

it('never opens readers for rejected input', function () {
    $factory = Mockery::mock(ReaderFactory::class);
    $factory->shouldNotReceive('open');
    app()->instance(ReaderFactory::class, $factory);
    app(IpLookup::class)->lookup('invalid');
    app(IpLookup::class)->lookup('127.0.0.1');
});

it('always closes an opened reader including failures', function ($outcome) {
    $real = new Reader(__DIR__.'/../Fixtures/country.mmdb');
    $metadata = $real->metadata();
    $model = $real->country('8.8.8.8');
    $real->close();
    $reader = Mockery::mock(Reader::class);
    $reader->shouldReceive('metadata')->once()->andReturn($metadata);
    if ($outcome === 'found') {
        $reader->shouldReceive('country')->once()->andReturn($model);
    } else {
        $reader->shouldReceive('country')->once()->andThrow(match ($outcome) {
            'missing' => new AddressNotFoundException('Missing'),
            'corrupt' => new InvalidDatabaseException('Corrupt'),
            'bug' => new LogicException('Programming defect'),
        });
    }
    $reader->shouldReceive('close')->once();
    $factory = Mockery::mock(ReaderFactory::class);
    $factory->shouldReceive('open')->once()->andReturn($reader);
    app()->instance(ReaderFactory::class, $factory);
    if ($outcome === 'bug') {
        expect(fn () => app(LocalDatabase::class)->read('country', '8.8.8.8'))->toThrow(LogicException::class, 'Programming defect');
    } else {
        expect(app(LocalDatabase::class)->read('country', '8.8.8.8')->status)->toBe(match ($outcome) {
            'found' => LookupStatus::Found,
            'missing' => LookupStatus::NotFound,
            'corrupt' => LookupStatus::Unavailable,
        });
    }
})->with(['found', 'missing', 'corrupt', 'bug']);

it('rejects stream wrappers before any IO', function () {
    config(['ip-analyzer.databases.country' => 'https://example.com/db.mmdb']);
    expect(fn () => app(LocalDatabase::class)->read('country'))->toThrow(InvalidArgumentException::class);
});

it('uses only the local SDK reader in runtime source', function () {
    $source = file_get_contents(__DIR__.'/../../src/Lookup/ReaderFactory.php');
    expect($source)->toContain('use GeoIp2\\Database\\Reader;')->not->toContain('WebService');
});

it('closes metadata readers without querying arbitrary records', function ($source) {
    $real = new Reader(__DIR__.'/../Fixtures/country.mmdb');
    $metadata = $real->metadata();
    $real->close();
    $reader = Mockery::mock(Reader::class);
    $reader->shouldReceive('metadata')->once()->andReturn($metadata);
    $reader->shouldNotReceive('country');
    $reader->shouldNotReceive('asn');
    $reader->shouldReceive('close')->once();
    $factory = Mockery::mock(ReaderFactory::class);
    $factory->shouldReceive('open')->once()->andReturn($reader);
    app()->instance(ReaderFactory::class, $factory);
    expect(app(LocalDatabase::class)->read($source)->status)->toBe(
        $source === 'country' ? LookupStatus::Found : LookupStatus::Unavailable);
})->with(['country', 'asn']);

it('rejects a directory as unavailable and nonlocal configuration as invalid', function () {
    config(['ip-analyzer.databases.country' => __DIR__]);
    expect(app(LocalDatabase::class)->read('country')->errorCode)->toBe('file_unreadable');
    config(['ip-analyzer.databases.country' => 'php://memory']);
    expect(fn () => app(LocalDatabase::class)->read('country'))->toThrow(InvalidArgumentException::class);
});
