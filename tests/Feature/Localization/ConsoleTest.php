<?php

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Trianity\IpAnalyzer\Console\UpdateProgress;
use Trianity\IpAnalyzer\Update\Progress\Database;
use Trianity\IpAnalyzer\Update\Progress\Phase;
use Trianity\IpAnalyzer\Update\Progress\Snapshot;
use Trianity\IpAnalyzer\Update\RemoteResponse;
use Trianity\IpAnalyzer\Update\Transport;

it('renders localized phases and summaries with a retained reporter', function () {
    $output = new BufferedOutput;
    app()->setLocale('hu');
    $reporter = new UpdateProgress($output);
    $reporter->report(new Snapshot(Phase::LocalValidation, Database::Country, 1, null, 2, null, finished: true));
    $reporter->finish(0, 2);
    expect($output->fetch())->toContain('Ország', 'Helyi MMDB', '1 tartomány', 'Teljes futási idő: 2.0 s');
    app()->setLocale('en');
    $reporter->report(new Snapshot(Phase::LocalValidation, Database::Country, 2, null, 3, null, finished: true));
    $reporter->finish(130, 3);
    expect($output->fetch())->toContain('Country', 'Local MMDB', '2 ranges', 'Interrupted', 'Total duration: 3.0 s');
});

it('localizes only stderr when JSON progress is requested', function ($locale, $expected) {
    app()->setLocale($locale);
    app('translator')->setFallback('hu');
    config(['ip-analyzer.update.account_id' => '1234', 'ip-analyzer.update.license_key' => 'secret']);
    [$output, $out, $err] = separatedProgressOutput();
    $transport = Mockery::mock(Transport::class);
    $transport->shouldReceive('request')->twice()->andReturn(new RemoteResponse(200));
    app()->instance(Transport::class, $transport);
    expect(Artisan::call('ip-data:update', ['--check' => true, '--json' => true, '--progress' => true], $output))->toBe(0);
    expect(progressText($err))->toContain($expected)->not->toContain('secret');
    expect(json_decode(progressText($out), true, flags: JSON_THROW_ON_ERROR)['results'][0]['status'])->toBe('update_available');
    fclose($out);
    fclose($err);
})->with([['en', 'Total duration'], ['hu', 'Teljes futási idő'], ['de', 'Total duration']]);

it('localizes command descriptions at read time rather than construction time', function () {
    $commands = Artisan::all();
    app()->setLocale('hu');
    expect($commands['ip-data:update']->getDescription())->toContain('frissítése')
        ->and($commands['ip-data:lookup']->getDescription())->toContain('lekérdezése')
        ->and($commands['ip-data:status']->getDescription())->toContain('ellenőrzése');
    app()->setLocale('en');
    expect($commands['ip-data:update']->getDescription())->toContain('Explicitly download');
    Artisan::call('help', ['command_name' => 'ip-data:update']);
    expect(Artisan::output())->toContain('Explicitly download');
});

it('preserves byte-for-byte JSON including configuration messages across locales', function () {
    config(['ip-analyzer.update.account_id' => null, 'ip-analyzer.update.license_key' => null]);
    $outputs = [];
    foreach (['en', 'hu', 'de'] as $locale) {
        app()->setLocale($locale);
        expect(Artisan::call('ip-data:update', ['--json' => true]))->toBe(2);
        $outputs[] = Artisan::output();
    }
    expect($outputs[1])->toBe($outputs[0])->and($outputs[2])->toBe($outputs[0]);
    expect(json_decode($outputs[0], true)['message'])->toBe('MaxMind Account ID and License Key are required.');
});

it('localizes human configuration errors while retaining machine codes', function () {
    app()->setLocale('hu');
    config(['ip-analyzer.update.account_id' => null, 'ip-analyzer.update.license_key' => null]);
    expect(Artisan::call('ip-data:update', ['--no-progress' => true]))->toBe(2);
    expect(Artisan::output())->toContain('MaxMind-fiókazonosító és licenckulcs szükséges', 'invalid_update_configuration')
        ->not->toContain('MaxMind Account ID and License Key are required.');
});

it('localizes human statuses warnings and sanitized errors without leaking upstream text', function () {
    app()->setLocale('hu');
    config(['ip-analyzer.update.account_id' => '1234', 'ip-analyzer.update.license_key' => 'hidden-key']);
    $transport = Mockery::mock(Transport::class);
    $transport->shouldReceive('request')->once()->andReturn(new RemoteResponse(200));
    app()->instance(Transport::class, $transport);
    expect(Artisan::call('ip-data:update', ['--database' => ['country'], '--check' => true, '--no-progress' => true]))->toBe(0);
    expect(Artisan::output())->toContain('A távoli verzió nem igazolható.', 'Frissítés szükséges', 'update_available')
        ->not->toContain('hidden-key');
    $transport = Mockery::mock(Transport::class);
    $transport->shouldReceive('request')->once()->andThrow(new RuntimeException('hidden-key signed-r2-url'));
    app()->instance(Transport::class, $transport);
    expect(Artisan::call('ip-data:update', ['--check' => true, '--no-progress' => true]))->toBe(1);
    expect(Artisan::output())->toContain('Az adatbázis-frissítés sikertelen.')->not->toContain('hidden-key', 'signed-r2-url');
});

it('keeps lookup and status JSON and custom rule messages identical across locales', function () {
    config(['ip-analyzer.databases.country' => __DIR__.'/../../Fixtures/country.mmdb',
        'ip-analyzer.databases.asn' => __DIR__.'/../../Fixtures/asn.mmdb',
        'ip-analyzer.rules.country' => [['id' => 'custom', 'value' => 'HU', 'reason_code' => 'custom_reason', 'severity' => 'info', 'message' => 'CUSTOM SZÖVEG']]]);
    foreach (['ip-data:lookup' => ['ip' => '8.8.8.8'], 'ip-data:status' => []] as $command => $args) {
        app()->setLocale('hu');
        Artisan::call($command, [...$args, '--json' => true]);
        $hu = Artisan::output();
        app()->setLocale('en');
        Artisan::call($command, [...$args, '--json' => true]);
        expect(Artisan::output())->toBe($hu);
        if ($command === 'ip-data:lookup') {
            expect(json_decode($hu, true)['matches'][0]['message'])->toBe('CUSTOM SZÖVEG');
            app()->setLocale('hu');
            Artisan::call($command, $args);
            expect(Artisan::output())->toContain('Ország: Megtalálható', 'CUSTOM SZÖVEG', 'custom_reason');
        }
    }
});

it('preserves quiet and no-progress with localized output', function ($locale, $quiet) {
    app()->setLocale($locale);
    config(['ip-analyzer.update.account_id' => '1234', 'ip-analyzer.update.license_key' => 'secret']);
    [$output, $out, $err] = separatedProgressOutput();
    $transport = Mockery::mock(Transport::class);
    $transport->shouldReceive('request')->twice()->andReturn(new RemoteResponse(200));
    app()->instance(Transport::class, $transport);
    expect(Artisan::call('ip-data:update', ['--check' => true, '--json' => true, '--progress' => true, $quiet ? '--quiet' : '--no-progress' => true], $output))->toBe(0);
    expect(progressText($err))->toBe('');
    if ($quiet) {
        expect(progressText($out))->toBe('');
    } else {
        expect(json_decode(progressText($out), true, flags: JSON_THROW_ON_ERROR)['results'])->toHaveCount(2);
    }
    fclose($out);
    fclose($err);
})->with([['en', false], ['en', true], ['hu', false], ['hu', true]]);

it('keeps successful check JSON byte-identical with either locale', function () {
    config(['ip-analyzer.update.account_id' => '1234', 'ip-analyzer.update.license_key' => 'secret']);
    $transport = Mockery::mock(Transport::class);
    $transport->shouldReceive('request')->times(4)->andReturn(new RemoteResponse(200));
    app()->instance(Transport::class, $transport);
    app()->setLocale('hu');
    expect(Artisan::call('ip-data:update', ['--check' => true, '--json' => true]))->toBe(0);
    $hu = Artisan::output();
    app()->setLocale('en');
    expect(Artisan::call('ip-data:update', ['--check' => true, '--json' => true]))->toBe(0);
    expect(Artisan::output())->toBe($hu);
});

it('localizes byte amounts phase ETA wait time and all machine phases', function () {
    app()->setLocale('en');
    $output = new BufferedOutput;
    $reporter = new UpdateProgress($output);
    $reporter->report(new Snapshot(Phase::Download, Database::Asn, 100, 200, 1.5, 2.5, 120, true));
    expect($output->fetch())->toContain('ASN', 'Downloading', '100 bytes / 200 (50.0%)', 'Elapsed: 1.5 s', 'Phase remaining time: 2.5 s', 'Wait/timeout: 120 s');
    foreach (Phase::cases() as $phase) {
        expect($phase->value)->toMatch('/^[a-z_]+$/D');
        foreach (['en', 'hu'] as $locale) {
            expect(app('translator')->has('ip-analyzer::messages.phases.'.$phase->value, $locale, false))->toBeTrue();
        }
    }
});
