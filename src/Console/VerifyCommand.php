<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Console;

use InvalidArgumentException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Throwable;
use Trianity\IpAnalyzer\Update\DatabaseVerifier;
use Trianity\IpAnalyzer\Update\Progress\Interrupted;
use Trianity\IpAnalyzer\Update\Progress\InterruptHandler;
use Trianity\IpAnalyzer\Update\Progress\MonotonicClock;
use Trianity\IpAnalyzer\Update\Progress\NullObserver;
use Trianity\IpAnalyzer\Update\Progress\Phase;
use Trianity\IpAnalyzer\Update\Progress\Progress;
use Trianity\IpAnalyzer\Update\UpdateConfigurationException;

final class VerifyCommand extends DataCommand
{
    protected $signature = 'ip-data:verify {--database=*} {--workers=} {--json} {--progress} {--no-progress}';

    public function handle(): int
    {
        $progress = $this->laravel->make(Progress::class);
        $clock = $this->laravel->make(MonotonicClock::class);
        $started = $clock->now();
        $reporter = null;
        $exit = 1;
        $signals = new InterruptHandler;
        $output = $this->output->getOutput();
        if (! $output->isQuiet() && ! $this->option('no-progress') && (! $this->option('json') || $this->option('progress'))) {
            $channel = $this->option('json')
                ? ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : new StreamOutput(fopen('php://stderr', 'wb')))
                : $output;
            $reporter = new UpdateProgress($channel, databases: $this->option('database'));
        }
        $progress->observe($reporter ?? new NullObserver);
        $signals->install($progress);
        try {
            $progress->start(Phase::Configuration);
            $results = $this->laravel->make(DatabaseVerifier::class)->run(
                $this->option('database'), $this->option('workers'),
            );
            $progress->checkpoint();
            $failed = count(array_filter($results, fn ($result) => $result->status === 'failed'));
            $busy = count(array_filter($results, fn ($result) => $result->status === 'busy'));
            $succeeded = count($results) - $failed - $busy;
            $exit = $failed > 0 || ($busy > 0 && $succeeded > 0) ? 1 : ($busy > 0 ? 3 : 0);
            $reporter?->complete($exit);
            $reporter?->endLine();
            $this->renderResult(['results' => $results, 'partialFailure' => ($failed + $busy > 0) && $succeeded > 0]);

            return $exit;
        } catch (Interrupted) {
            $exit = 130;
            $reporter?->complete($exit);
            $reporter?->endLine();
            $this->renderResult(['error' => 'interrupted', 'results' => [], 'partialFailure' => false]);

            return $exit;
        } catch (UpdateConfigurationException|InvalidArgumentException $exception) {
            $reporter?->complete(2);
            $reporter?->endLine();
            $this->renderResult(['error' => 'invalid_update_configuration', 'message' => $exception->getMessage(), 'results' => [], 'partialFailure' => false]);

            return $exit = 2;
        } catch (Throwable) {
            $reporter?->complete(1);
            $reporter?->endLine();
            $this->renderResult(['error' => 'verification_failed', 'results' => [], 'partialFailure' => false]);

            return $exit = 1;
        } finally {
            $signals->restore();
            $elapsed = max(0, $clock->now() - $started);
            $reporter?->finish($exit, $elapsed);
            if ($reporter === null && ! $this->option('json')) {
                $output->writeln(UpdateProgress::summary($exit, $elapsed));
            }
            $progress->observe(new NullObserver);
        }
    }
}
