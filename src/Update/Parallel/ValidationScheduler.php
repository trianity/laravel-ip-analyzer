<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update\Parallel;

use Trianity\IpAnalyzer\Update\Progress\Phase;
use Trianity\IpAnalyzer\Update\Progress\Progress;

final class ValidationScheduler
{
    public function __construct(
        private readonly SequentialValidationExecutor $sequential,
        private readonly ParallelValidationExecutorContract $parallel,
        private readonly Progress $progress,
    ) {}

    /**
     * @param  list<ValidationTask>  $tasks
     * @return array<string, ValidationOutcome>
     */
    public function execute(array $tasks, int $workers, int $timeout): array
    {
        $concurrency = min($workers, count($tasks));
        if ($concurrency < 2) {
            return $this->sequential->execute($tasks, 1, $timeout);
        }
        if (! $this->parallel->supported()) {
            $this->progress->start(Phase::SequentialFallback);

            return $this->sequential->execute($tasks, 1, $timeout);
        }
        foreach ($tasks as $task) {
            $this->progress->start(Phase::ValidationWaiting, $task->database);
        }

        try {
            return $this->parallel->execute($tasks, $concurrency, $timeout);
        } catch (ParallelUnavailable) {
            $this->progress->start(Phase::SequentialFallback);

            return $this->sequential->execute($tasks, 1, $timeout);
        }
    }
}
