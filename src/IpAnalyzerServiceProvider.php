<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer;

use Illuminate\Support\ServiceProvider;
use Trianity\IpAnalyzer\Analysis\LocalIpAnalyzer;
use Trianity\IpAnalyzer\Contracts\IpAnalyzer;
use Trianity\IpAnalyzer\Contracts\IpLookup;
use Trianity\IpAnalyzer\Lookup\MaxMindIpLookup;

final class IpAnalyzerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ip-analyzer.php', 'ip-analyzer');
        $this->app->bind(IpLookup::class, MaxMindIpLookup::class);
        $this->app->bind(IpAnalyzer::class, LocalIpAnalyzer::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/ip-analyzer.php' => config_path('ip-analyzer.php'),
            ], 'ip-analyzer-config');
        }
    }
}
