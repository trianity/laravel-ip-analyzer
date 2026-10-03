<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Trianity\IpAnalyzer\Update\RemoteResponse;
use Trianity\IpAnalyzer\Update\Transport;

it('requires credentials only for update and returns a single sanitized document', function () {
    config(['app.debug' => true, 'ip-analyzer.update.account_id' => null, 'ip-analyzer.update.license_key' => null]);
    expect(Artisan::call('ip-data:update', ['--json' => true]))->toBe(2);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error'])->toBe('invalid_update_configuration');
    expect(Artisan::call('ip-data:lookup', ['ip' => '127.0.0.1', '--json' => true]))->toBe(0);
});

it('never downloads on provider boot status about lookup or scheduler resolution', function () {
    $transport = Mockery::mock(Transport::class);
    $transport->shouldNotReceive('request');
    app()->instance(Transport::class, $transport);
    Artisan::call('ip-data:lookup', ['ip' => '127.0.0.1']);
    Artisan::call('ip-data:status');
    Artisan::call('about');
    expect(array_filter(app(Schedule::class)->events(), fn ($e) => str_contains($e->command ?? '', 'ip-data:update')))->toBe([]);
});

it('rejects unknown database selections', function () {
    expect(Artisan::call('ip-data:update', ['--database' => ['city'], '--json' => true]))->toBe(2);
});

it('renders a successful check in human and JSON modes', function ($json) {
    config(['ip-analyzer.update.account_id' => '1234', 'ip-analyzer.update.license_key' => 'synthetic-secret']);
    $transport = Mockery::mock(Transport::class);
    $transport->shouldReceive('request')->once()->with('HEAD', Mockery::any(), Mockery::any(), null)->andReturn(new RemoteResponse(200));
    app()->instance(Transport::class, $transport);
    expect(Artisan::call('ip-data:update', ['--database' => ['country'], '--check' => true, '--json' => $json]))->toBe(0);
    if ($json) {
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($result['results'][0]['status'])->toBe('update_available')->and($result['partialFailure'])->toBeFalse();
    } else {
        expect(Artisan::output())->toContain('update_available', 'Total duration');
    }
})->with([true, false]);

it('classifies invalid shared lookup settings as configuration errors before HTTP', function () {
    config(['ip-analyzer.update.account_id' => '1234', 'ip-analyzer.update.license_key' => 'synthetic-secret', 'ip-analyzer.max_age_days' => -1]);
    $transport = Mockery::mock(Transport::class);
    $transport->shouldNotReceive('request');
    app()->instance(Transport::class, $transport);
    expect(Artisan::call('ip-data:update', ['--database' => ['country'], '--json' => true]))->toBe(2);
});
