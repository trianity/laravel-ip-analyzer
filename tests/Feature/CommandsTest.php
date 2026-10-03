<?php

use Illuminate\Support\Facades\Artisan;
use Trianity\IpAnalyzer\Contracts\IpAnalyzer;
use Trianity\IpAnalyzer\Support\Clock;

beforeEach(function () {
    config(['ip-analyzer.databases.country' => __DIR__.'/../Fixtures/country.mmdb',
        'ip-analyzer.databases.asn' => __DIR__.'/../Fixtures/asn.mmdb']);
    $clock = Mockery::mock(Clock::class);
    $clock->shouldReceive('now')->andReturn(1700000000);
    app()->instance(Clock::class, $clock);
});

it('outputs exactly one JSON lookup document', function ($ip, $status, $exit) {
    expect(Artisan::call('ip-data:lookup', ['ip' => $ip, '--json' => true]))->toBe($exit);
    $data = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($data['facts']['countryStatus'])->toBe($status)->and($data['matches'])->toBe([]);
})->with([['8.8.8.8', 'found', 0], ['1.1.1.1', 'not_found', 0], ['127.0.0.1', 'non_public', 0], ['invalid', 'invalid_input', 2]]);

it('reports unavailable lookup sources with exit one', function () {
    config(['ip-analyzer.databases.asn' => '/nonexistent/asn.mmdb']);
    expect(Artisan::call('ip-data:lookup', ['ip' => '8.8.8.8', '--json' => true]))->toBe(1);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['facts']['asnErrorCode'])->toBe('file_unreadable');
});

it('reports database status without performing any IP lookup', function () {
    expect(Artisan::call('ip-data:status', ['--json' => true]))->toBe(0);
    $data = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($data['country']['status'])->toBe('found')->and($data['country']['metadata']['ageDays'])->toEqual(0);
});

it('distinguishes stale lookup and status exit codes', function () {
    $clock = Mockery::mock(Clock::class);
    $clock->shouldReceive('now')->andReturn(1800000000);
    app()->instance(Clock::class, $clock);
    expect(Artisan::call('ip-data:lookup', ['ip' => '8.8.8.8', '--json' => true]))->toBe(0);
    expect(json_decode(Artisan::output(), true)['facts']['countryDatabase']['stale'])->toBeTrue();
    expect(Artisan::call('ip-data:status', ['--json' => true]))->toBe(1);
});

it('renders human output', function () {
    $this->artisan('ip-data:lookup', ['ip' => '8.8.8.8'])->expectsOutputToContain('HU')->assertSuccessful();
    $this->artisan('ip-data:status')->expectsOutputToContain('GeoLite2-Country')->assertSuccessful();
});

it('returns a sanitized JSON error for configuration and custom failures', function () {
    config(['ip-analyzer.max_age_days' => -1]);
    expect(Artisan::call('ip-data:status', ['--json' => true]))->toBe(2);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe(['error' => 'invalid_configuration_or_rule']);
});

it('registers its about section', function () {
    Artisan::call('about', ['--json' => true]);
    expect(Artisan::output())->toContain('i_p_analyzer')->toContain('version');
});

it('returns exit two and sanitized JSON for a custom rule exception', function () {
    $analyzer = Mockery::mock(IpAnalyzer::class);
    $analyzer->shouldReceive('analyze')->once()->andThrow(new RuntimeException('/private/path secret'));
    app()->instance(IpAnalyzer::class, $analyzer);
    expect(Artisan::call('ip-data:lookup', ['ip' => '8.8.8.8', '--json' => true]))->toBe(2);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe(['error' => 'invalid_configuration_or_rule']);
});

it('does not turn observation matches into command failures', function () {
    config(['ip-analyzer.rules.country' => [[
        'id' => 'country-test', 'value' => 'HU', 'reason_code' => 'test',
        'severity' => 'high', 'message' => 'Synthetic observation',
    ]]]);
    expect(Artisan::call('ip-data:lookup', ['ip' => '8.8.8.8', '--json' => true]))->toBe(0);
    expect(json_decode(Artisan::output(), true)['matches'][0]['ruleId'])->toBe('country-test');
});

it('reports each unavailable status independently', function ($path, $error) {
    config(['ip-analyzer.databases.country' => __DIR__.'/../Fixtures/'.$path]);
    expect(Artisan::call('ip-data:status', ['--json' => true]))->toBe(1);
    $data = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($data['country']['status'])->toBe('unavailable')
        ->and($data['country']['errorCode'])->toBe($error)
        ->and($data['asn']['status'])->toBe('found');
})->with([['absent.mmdb', 'file_unreadable'], ['README.md', 'invalid_database'], ['asn.mmdb', 'database_type_mismatch']]);
