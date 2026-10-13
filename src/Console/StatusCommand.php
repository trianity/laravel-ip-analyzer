<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Console;

use Throwable;
use Trianity\IpAnalyzer\Enums\LookupStatus;
use Trianity\IpAnalyzer\Lookup\LocalDatabase;
use Trianity\IpAnalyzer\Support\Messages;
use Trianity\IpAnalyzer\Update\ScheduleConfiguration;
use Trianity\IpAnalyzer\Update\ScheduleConfigurationException;

final class StatusCommand extends DataCommand
{
    protected $signature = 'ip-data:status {--json}';

    public function handle(): int
    {
        try {
            $databases = $this->laravel->make(LocalDatabase::class);
            $databaseResults = [
                'country' => $databases->read('country'),
                'asn' => $databases->read('asn'),
            ];
            try {
                $schedule = ScheduleConfiguration::fromConfig(
                    $this->laravel->make('config'),
                    $this->laravel->make(Messages::class),
                )->status();
            } catch (ScheduleConfigurationException $exception) {
                $this->renderResult([
                    ...$databaseResults,
                    'schedule' => ScheduleConfiguration::invalidStatus(
                        $this->laravel->make('config'),
                        $exception->setting,
                    ),
                ]);

                return 2;
            }
            $results = [
                ...$databaseResults,
                'schedule' => $schedule,
            ];
            $this->renderResult($results);
            foreach ($databaseResults as $result) {
                if ($result->status === LookupStatus::Unavailable || $result->metadata?->stale) {
                    return 1;
                }
            }

            return 0;
        } catch (Throwable $e) {
            return $this->failed();
        }
    }
}
