<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer;

use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;
use Trianity\IpAnalyzer\Analysis\LocalIpAnalyzer;
use Trianity\IpAnalyzer\Console\LookupCommand;
use Trianity\IpAnalyzer\Console\StatusCommand;
use Trianity\IpAnalyzer\Console\UpdateCommand;
use Trianity\IpAnalyzer\Console\VerifyCommand;
use Trianity\IpAnalyzer\Contracts\IpAnalyzer;
use Trianity\IpAnalyzer\Contracts\IpLookup;
use Trianity\IpAnalyzer\Lookup\MaxMindIpLookup;
use Trianity\IpAnalyzer\Support\PackageVersion;
use Trianity\IpAnalyzer\Update\GuzzleTransport;
use Trianity\IpAnalyzer\Update\Parallel\ParallelValidationExecutor;
use Trianity\IpAnalyzer\Update\Parallel\ParallelValidationExecutorContract;
use Trianity\IpAnalyzer\Update\Parallel\SymfonyWorkerProcessFactory;
use Trianity\IpAnalyzer\Update\Parallel\ValidationExecutor;
use Trianity\IpAnalyzer\Update\Parallel\ValidationScheduler;
use Trianity\IpAnalyzer\Update\Parallel\WorkerProcessFactory;
use Trianity\IpAnalyzer\Update\Progress\Progress;
use Trianity\IpAnalyzer\Update\Transport;

final class IpAnalyzerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ip-analyzer.php', 'ip-analyzer');
        $this->app->singleton(Progress::class);
        $this->app->bind(WorkerProcessFactory::class, SymfonyWorkerProcessFactory::class);
        $this->app->bind(ParallelValidationExecutorContract::class, ParallelValidationExecutor::class);
        $this->app->bind(ValidationExecutor::class, ValidationScheduler::class);
        $this->app->bind(Transport::class, fn ($app) => new GuzzleTransport(progress: $app->make(Progress::class)));
        $this->app->bind(IpLookup::class, MaxMindIpLookup::class);
        $this->app->bind(IpAnalyzer::class, LocalIpAnalyzer::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'ip-analyzer');
        if ($this->app->runningInConsole()) {
            $this->commands([LookupCommand::class, StatusCommand::class, UpdateCommand::class, VerifyCommand::class]);
            AboutCommand::add('IP Analyzer', fn () => ['Version' => PackageVersion::get()]);
            $this->publishes([
                __DIR__.'/../config/ip-analyzer.php' => config_path('ip-analyzer.php'),
            ], 'ip-analyzer-config');
            $this->publishes([__DIR__.'/../lang' => lang_path('vendor/ip-analyzer')], 'ip-analyzer-translations');
        }
    }
}
