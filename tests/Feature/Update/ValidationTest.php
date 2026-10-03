<?php

use MaxMind\Db\Reader;
use Trianity\IpAnalyzer\Support\Clock;
use Trianity\IpAnalyzer\Update\CandidateValidator;
use Trianity\IpAnalyzer\Update\UpdateFailure;
use Trianity\IpAnalyzer\Update\UpdateOptions;

it('validates real synthetic metadata and every reachable network record', function () {
    $result = app(CandidateValidator::class)->validate('country', __DIR__.'/../../Fixtures/country.mmdb', app(UpdateOptions::class));
    expect($result->buildEpoch)->toBe(1700000000)->and($result->sha256)->toBe(hash_file('sha256', __DIR__.'/../../Fixtures/country.mmdb'));
});

it('rejects wrong types and corrupt record payloads even when metadata is readable', function () {
    expect(fn () => app(CandidateValidator::class)->validate('country', __DIR__.'/../../Fixtures/asn.mmdb', app(UpdateOptions::class)))->toThrow(UpdateFailure::class);
    $source = __DIR__.'/../../Fixtures/country.mmdb';
    $reader = new Reader($source);
    $offset = $reader->metadata()->nodeCount * 6 + 16;
    $reader->close();
    $data = file_get_contents($source);
    $data[$offset] = "\x1f";
    $path = tempnam(sys_get_temp_dir(), 'invalid-record-');
    try {
        file_put_contents($path, $data);
        expect(fn () => app(CandidateValidator::class)->validate('country', $path, app(UpdateOptions::class)))->toThrow(UpdateFailure::class, 'invalid_database');
    } finally {
        unlink($path);
    }
});

it('rejects future builds and enforces a finite record traversal budget', function () {
    $clock = Mockery::mock(Clock::class);
    $clock->shouldReceive('now')->andReturn(1600000000);
    app()->instance(Clock::class, $clock);
    expect(fn () => app(CandidateValidator::class)->validate('country', __DIR__.'/../../Fixtures/country.mmdb', app(UpdateOptions::class)))->toThrow(UpdateFailure::class, 'invalid_build_epoch');
    $clock = Mockery::mock(Clock::class);
    $clock->shouldReceive('now')->andReturn(1800000000);
    app()->instance(Clock::class, $clock);
    config(['ip-analyzer.update.max_records' => 1]);
    expect(fn () => app(CandidateValidator::class)->validate('country', __DIR__.'/../../Fixtures/country.mmdb', app(UpdateOptions::class)))->toThrow(UpdateFailure::class, 'validation_limit');
});
