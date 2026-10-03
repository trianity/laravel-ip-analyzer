<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Console;

use InvalidArgumentException;
use Throwable;
use Trianity\IpAnalyzer\Update\DatabaseUpdater;
use Trianity\IpAnalyzer\Update\UpdateConfigurationException;

final class UpdateCommand extends DataCommand
{
    protected $signature = 'ip-data:update {--database=*} {--force} {--check} {--json}';

    protected $description = 'Explicitly download and atomically update local MaxMind databases';

    public function handle(): int
    {
        try {
            $results = $this->laravel->make(DatabaseUpdater::class)->run($this->option('database'), $this->option('force'), $this->option('check'));
            $failed = count(array_filter($results, fn ($result) => $result->status === 'failed'));
            $busy = count(array_filter($results, fn ($result) => $result->status === 'busy'));
            $succeeded = count($results) - $failed - $busy;
            $installed = count(array_filter($results, fn ($result) => $result->installed));
            $exit = $failed > 0 || ($busy > 0 && $installed > 0) ? 1 : ($busy > 0 ? 3 : 0);
            $this->renderResult(['results' => $results, 'partialFailure' => ($failed + $busy > 0) && ($succeeded > 0 || $installed > 0)]);

            return $exit;
        } catch (UpdateConfigurationException $exception) {
            $this->renderResult(['error' => 'invalid_update_configuration', 'message' => $exception->getMessage(), 'results' => [], 'partialFailure' => false]);

            return 2;
        } catch (InvalidArgumentException) {
            $this->renderResult(['error' => 'invalid_update_configuration', 'results' => [], 'partialFailure' => false]);

            return 2;
        } catch (Throwable) {
            // Do not let debug/verbosity expose upstream exceptions or signed URLs.
            $this->renderResult(['error' => 'update_failed', 'results' => [], 'partialFailure' => false]);

            return 1;
        }
    }
}
