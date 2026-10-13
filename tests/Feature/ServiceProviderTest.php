<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Trianity\IpAnalyzer\Contracts\IpAnalyzer;
use Trianity\IpAnalyzer\Contracts\IpLookup;
use Trianity\IpAnalyzer\IpAnalyzerServiceProvider;

it('resolves the public contracts', function () {
    expect(app(IpLookup::class))->toBeInstanceOf(IpLookup::class);
    expect(app(IpAnalyzer::class))->toBeInstanceOf(IpAnalyzer::class);
});

it('defaults to empty observation rules', function () {
    expect(config('ip-analyzer.custom_rules'))->toBe([])
        ->and(config('ip-analyzer.rules.asn'))->toBe([]);
});

it('publishes a cacheable configuration', function () {
    $paths = ServiceProvider::pathsToPublish(
        IpAnalyzerServiceProvider::class, 'ip-analyzer-config');
    expect($paths)->toHaveCount(1);
    $this->artisan('vendor:publish', ['--tag' => 'ip-analyzer-config', '--force' => true])->assertSuccessful();
    $published = config_path('ip-analyzer.php');
    try {
        expect(file_get_contents($published))->toBe(file_get_contents(array_key_first($paths)));
        $contents = str_replace("'max_age_days' => 30", "'max_age_days' => 17", file_get_contents($published));
        $contents = str_replace("env('IP_ANALYZER_UPDATE_SCHEDULE', false)", 'true', $contents);
        $contents = str_replace("env('IP_ANALYZER_UPDATE_CRON', '15 3 * * *')", "'5 4 * * 2'", $contents);
        $contents = str_replace("env('IP_ANALYZER_UPDATE_TIMEZONE', 'UTC')", "'Europe/Budapest'", $contents);
        file_put_contents($published, $contents);
        $this->artisan('config:cache')->assertSuccessful();
        $cached = require app()->getCachedConfigPath();
        expect($cached['ip-analyzer']['custom_rules'])->toBe([])
            ->and($cached['ip-analyzer']['update']['schedule_enabled'])->toBeTrue()
            ->and($cached['ip-analyzer']['update']['schedule_cron'])->toBe('5 4 * * 2')
            ->and($cached['ip-analyzer']['update']['schedule_timezone'])->toBe('Europe/Budapest');
        $this->refreshApplication();
        expect(app()->configurationIsCached())->toBeTrue()
            ->and(config('ip-analyzer.max_age_days'))->toBe(17);
        $events = array_filter(app(Schedule::class)->events(), fn ($event) => $event->description === 'ip-analyzer:update');
        expect($events)->toHaveCount(1);
        expect(app(IpAnalyzer::class)->analyze('127.0.0.1')->matches)->toBe([]);
    } finally {
        $this->artisan('config:clear')->assertSuccessful();
        @unlink($published);
    }
});
