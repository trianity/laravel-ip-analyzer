<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Console;

use Throwable;
use Trianity\IpAnalyzer\Contracts\IpAnalyzer;
use Trianity\IpAnalyzer\Enums\LookupStatus;

final class LookupCommand extends DataCommand
{
    protected $signature = 'ip-data:lookup {ip} {--json}';

    public function handle(): int
    {
        // CLI boundary only: the PHP services propagate programming and custom-rule errors.
        try {
            $result = $this->laravel->make(IpAnalyzer::class)->analyze($this->argument('ip'));
            $this->renderResult($result);
            $statuses = [$result->facts->countryStatus, $result->facts->asnStatus];
            if (in_array(LookupStatus::InvalidInput, $statuses, true)) {
                return 2;
            }

            return in_array(LookupStatus::Unavailable, $statuses, true) ? 1 : 0;
        } catch (Throwable $e) {
            return $this->failed();
        }
    }
}
