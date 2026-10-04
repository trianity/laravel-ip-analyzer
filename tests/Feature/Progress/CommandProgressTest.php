<?php

use Illuminate\Support\Facades\Artisan;
use Trianity\IpAnalyzer\Update\Progress\Progress;
use Trianity\IpAnalyzer\Update\RemoteResponse;
use Trianity\IpAnalyzer\Update\Transport;

beforeEach(function () {
    app()->setLocale('hu');
    config(['ip-analyzer.update.account_id' => '1234', 'ip-analyzer.update.license_key' => 'SYNTHETIC-SECRET']);
});

it('announces both databases and safe check phases without download or install', function () {
    [$output, $out, $err] = separatedProgressOutput();
    $transport = Mockery::mock(Transport::class);
    $transport->shouldReceive('request')->twice()->andReturnUsing(function () use ($out) {
        expect(progressText($out))->toContain('több percig tarthat', 'HEAD');

        return new RemoteResponse(200);
    });
    app()->instance(Transport::class, $transport);
    expect(Artisan::call('ip-data:update', ['--check' => true, '--progress' => true], $output))->toBe(0);
    $text = progressText($out);
    expect($text)->toContain('Ország', 'ASN', 'Teljes futási idő')->not->toContain('Kicsomagolás', 'Telepítés', 'SYNTHETIC-SECRET', "\033", "\r");
    fclose($out);
    fclose($err);
});

it('keeps JSON stdout clean and routes explicit progress only to stderr', function ($progress) {
    [$output, $out, $err] = separatedProgressOutput();
    $transport = Mockery::mock(Transport::class);
    $transport->shouldReceive('request')->twice()->andReturn(new RemoteResponse(200));
    app()->instance(Transport::class, $transport);
    expect(Artisan::call('ip-data:update', ['--check' => true, '--json' => true, '--progress' => $progress], $output))->toBe(0);
    expect(json_decode(progressText($out), true, flags: JSON_THROW_ON_ERROR)['results'])->toHaveCount(2);
    if ($progress) {
        expect(progressText($err))->toContain('Ország', 'ASN', 'Teljes futási idő')->not->toContain("\033", "\r");
    } else {
        expect(progressText($err))->toBe('');
    }
    fclose($out);
    fclose($err);
})->with([false, true]);

it('lets no-progress and quiet override explicit progress', function ($quiet) {
    [$output, $out, $err] = separatedProgressOutput();
    $transport = Mockery::mock(Transport::class);
    $transport->shouldReceive('request')->twice()->andReturn(new RemoteResponse(200));
    app()->instance(Transport::class, $transport);
    $args = ['--check' => true, '--json' => true, '--progress' => true, $quiet ? '--quiet' : '--no-progress' => true];
    expect(Artisan::call('ip-data:update', $args, $output))->toBe(0);
    expect(progressText($err))->toBe('');
    if ($quiet) {
        expect(progressText($out))->toBe('');
    }
    fclose($out);
    fclose($err);
})->with([false, true]);

it('finishes failed commands and sanitizes unexpected errors', function () {
    [$output, $out, $err] = separatedProgressOutput();
    $transport = Mockery::mock(Transport::class);
    $transport->shouldReceive('request')->once()->andThrow(new RuntimeException('SYNTHETIC-SECRET signed-url'));
    app()->instance(Transport::class, $transport);
    expect(Artisan::call('ip-data:update', ['--check' => true, '--json' => true, '--progress' => true], $output))->toBe(1);
    expect(progressText($err))->toContain('Hiba', 'Teljes futási idő')->not->toContain('SYNTHETIC-SECRET', 'signed-url');
    expect(json_decode(progressText($out), true)['error'])->toBe('update_failed');
    fclose($out);
    fclose($err);
});

it('returns 130 on cancellation and restores a reusable command session', function () {
    [$output, $out, $err] = separatedProgressOutput();
    $transport = Mockery::mock(Transport::class);
    $transport->shouldReceive('request')->once()->andReturnUsing(function () {
        app(Progress::class)->cancel();

        return new RemoteResponse(200);
    });
    app()->instance(Transport::class, $transport);
    expect(Artisan::call('ip-data:update', ['--check' => true, '--json' => true, '--progress' => true], $output))->toBe(130);
    expect(progressText($err))->toContain('Megszakítva', 'Teljes futási idő');
    expect(json_decode(progressText($out), true)['error'])->toBe('interrupted');
    app(Progress::class)->checkpoint();
    fclose($out);
    fclose($err);
});

it('handles an actual SIGINT flag and restores the prior handler', function () {
    if (! function_exists('pcntl_signal') || ! function_exists('posix_kill')) {
        $this->markTestSkipped('Optional pcntl/posix unavailable.');
    }
    $before = pcntl_signal_get_handler(SIGINT);
    $async = pcntl_async_signals();
    [$output, $out, $err] = separatedProgressOutput();
    $transport = Mockery::mock(Transport::class);
    $transport->shouldReceive('request')->once()->andReturnUsing(function () {
        posix_kill(getmypid(), SIGINT);

        return new RemoteResponse(200);
    });
    app()->instance(Transport::class, $transport);
    expect(Artisan::call('ip-data:update', ['--check' => true, '--json' => true, '--progress' => true], $output))->toBe(130);
    expect(pcntl_signal_get_handler(SIGINT))->toBe($before)->and(pcntl_async_signals())->toBe($async);
    expect(progressText($err))->toContain('Megszakítva');
    fclose($out);
    fclose($err);
});

it('shows default non-TTY human phase lines and the total duration even with progress disabled', function ($disabled) {
    [$output, $out, $err] = separatedProgressOutput();
    $transport = Mockery::mock(Transport::class);
    $transport->shouldReceive('request')->twice()->andReturn(new RemoteResponse(200));
    app()->instance(Transport::class, $transport);
    expect(Artisan::call('ip-data:update', ['--check' => true, '--no-progress' => $disabled], $output))->toBe(0);
    $text = progressText($out);
    expect($text)->toContain('Teljes futási idő')->not->toContain("\033", "\r");
    if ($disabled) {
        expect($text)->not->toContain('több percig tarthat', 'HEAD');
    } else {
        expect($text)->toContain('több percig tarthat', 'Ország', 'ASN', 'HEAD');
    }
    fclose($out);
    fclose($err);
})->with([false, true]);
