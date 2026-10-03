<?php

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
        file_put_contents($published, str_replace("'max_age_days' => 30", "'max_age_days' => 17", file_get_contents($published)));
        $this->artisan('config:cache')->assertSuccessful();
        $cached = require app()->getCachedConfigPath();
        expect($cached['ip-analyzer']['custom_rules'])->toBe([]);
        $this->refreshApplication();
        expect(app()->configurationIsCached())->toBeTrue()
            ->and(config('ip-analyzer.max_age_days'))->toBe(17);
        expect(app(IpAnalyzer::class)->analyze('127.0.0.1')->matches)->toBe([]);
    } finally {
        $this->artisan('config:clear')->assertSuccessful();
        @unlink($published);
    }
});
