<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Trianity\IpAnalyzer\IpAnalyzerServiceProvider;
use Trianity\IpAnalyzer\Update\Parallel\ValidationExecutor;
use Trianity\IpAnalyzer\Update\Transport;
use Trianity\IpAnalyzer\Update\UpdateConfigurationException;
use Trianity\IpAnalyzer\Update\ValidationOptions;

function packageUpdateEvents(Schedule $schedule): array
{
    return array_values(array_filter(
        $schedule->events(),
        fn (Event $event): bool => $event->description === 'ip-analyzer:update',
    ));
}

function bootFreshProvider(): IpAnalyzerServiceProvider
{
    $provider = new IpAnalyzerServiceProvider(app());
    $provider->boot();

    return $provider;
}

it('registers no package update event by default', function () {
    expect(packageUpdateEvents(app(Schedule::class)))->toBe([]);
});

it('registers one named update event with safe defaults and normal worker configuration', function () {
    config([
        'ip-analyzer.update.schedule_enabled' => true,
        'ip-analyzer.validation.workers' => 2,
        'ip-analyzer.validation.worker_timeout' => 900,
    ]);

    bootFreshProvider();

    $events = packageUpdateEvents(app(Schedule::class));
    expect($events)->toHaveCount(1)
        ->and($events[0]->command)->toContain('ip-data:update')
        ->and($events[0]->command)->not->toContain('--check', '--force', '--workers')
        ->and($events[0]->expression)->toBe('15 3 * * *')
        ->and($events[0]->timezone)->toBe('UTC')
        ->and($events[0]->withoutOverlapping)->toBeTrue()
        ->and($events[0]->onOneServer)->toBeFalse()
        ->and($events[0]->runInBackground)->toBeFalse()
        ->and(app(ValidationOptions::class)->workers())->toBe(2)
        ->and(app(ValidationOptions::class)->timeout())->toBe(900);
});

it('uses custom cron and timezone without changing host events', function () {
    $schedule = app(Schedule::class);
    $host = $schedule->command('host:maintenance')->daily()->name('host:maintenance');
    config([
        'ip-analyzer.update.schedule_enabled' => true,
        'ip-analyzer.update.schedule_cron' => '15 3 * * 1,3,6',
        'ip-analyzer.update.schedule_timezone' => 'Europe/Budapest',
    ]);

    bootFreshProvider();

    $event = packageUpdateEvents($schedule)[0];
    expect($event->expression)->toBe('15 3 * * 1,3,6')
        ->and($event->timezone)->toBe('Europe/Budapest')
        ->and($schedule->events())->toContain($host)
        ->and($host->expression)->toBe('0 0 * * *');
});

it('uses defaults when an older published update configuration omits schedule keys', function () {
    config(['ip-analyzer.update' => ['schedule_enabled' => true]]);

    bootFreshProvider();

    $event = packageUpdateEvents(app(Schedule::class))[0];
    expect($event->expression)->toBe('15 3 * * *')
        ->and($event->timezone)->toBe('UTC');
});

it('does not duplicate registration and handles schedule resolution before the callback', function () {
    $schedule = app(Schedule::class);
    config(['ip-analyzer.update.schedule_enabled' => true]);

    $provider = bootFreshProvider();
    $provider->boot();

    expect(packageUpdateEvents($schedule))->toHaveCount(1);
});

it('registers each independent schedule instance', function () {
    config(['ip-analyzer.update.schedule_enabled' => true]);
    bootFreshProvider();
    $first = app(Schedule::class);

    app()->forgetInstance(Schedule::class);
    $second = app(Schedule::class);

    expect($second)->not->toBe($first)
        ->and(packageUpdateEvents($first))->toHaveCount(1)
        ->and(packageUpdateEvents($second))->toHaveCount(1);
});

it('rejects invalid enabled schedule settings with localized actionable errors', function (string $locale, array $settings, string $message) {
    app()->setLocale($locale);
    config(array_merge(['ip-analyzer.update.schedule_enabled' => true], $settings));

    expect(function (): void {
        bootFreshProvider();
        app(Schedule::class);
    })->toThrow(UpdateConfigurationException::class, $message);
})->with([
    ['en', ['ip-analyzer.update.schedule_cron' => 'not a cron'], 'ip-analyzer.update.schedule_cron'],
    ['hu', ['ip-analyzer.update.schedule_timezone' => 'Mars/Olympus'], 'ip-analyzer.update.schedule_timezone'],
    ['fr', ['ip-analyzer.update.schedule_cron' => 'invalid'], 'ip-analyzer.update.schedule_cron'],
]);

it('rejects a non-boolean scheduling switch', function () {
    config(['ip-analyzer.update.schedule_enabled' => 'true']);

    expect(function (): void {
        bootFreshProvider();
        app(Schedule::class);
    })
        ->toThrow(UpdateConfigurationException::class, 'ip-analyzer.update.schedule_enabled');
});

it('ignores irrelevant invalid cron and timezone while scheduling is disabled', function () {
    config([
        'ip-analyzer.update.schedule_enabled' => false,
        'ip-analyzer.update.schedule_cron' => ['invalid'],
        'ip-analyzer.update.schedule_timezone' => 'Mars/Olympus',
    ]);

    expect(fn () => bootFreshProvider())->not->toThrow(Throwable::class)
        ->and(packageUpdateEvents(app(Schedule::class)))->toBe([]);
});

it('does not invoke update services while registering the scheduled event', function () {
    config(['ip-analyzer.update.schedule_enabled' => true]);
    $transport = Mockery::mock(Transport::class);
    $transport->shouldNotReceive('request');
    app()->instance(Transport::class, $transport);
    $validator = Mockery::mock(ValidationExecutor::class);
    $validator->shouldNotReceive('execute');
    app()->instance(ValidationExecutor::class, $validator);

    bootFreshProvider();

    expect(packageUpdateEvents(app(Schedule::class)))->toHaveCount(1);
});
