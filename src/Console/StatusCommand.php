<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Console;

use Throwable;
use Trianity\IpAnalyzer\Enums\LookupStatus;
use Trianity\IpAnalyzer\Lookup\LocalDatabase;

final class StatusCommand extends DataCommand
{
    protected $signature = 'ip-data:status {--json}';

    public function handle(): int
    {
        try {
            $databases = $this->laravel->make(LocalDatabase::class);
            $results = ['country' => $databases->read('country'), 'asn' => $databases->read('asn')];
            $this->renderResult($results);
            foreach ($results as $result) {
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
