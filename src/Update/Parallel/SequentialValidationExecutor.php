<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update\Parallel;

use Trianity\IpAnalyzer\Update\CandidateValidator;
use Trianity\IpAnalyzer\Update\UpdateFailure;
use Trianity\IpAnalyzer\Update\UpdateOptions;

final class SequentialValidationExecutor implements ValidationExecutor
{
    public function __construct(
        private readonly CandidateValidator $validator,
        private readonly UpdateOptions $options,
    ) {}

    public function execute(array $tasks, int $workers, int $timeout): array
    {
        $outcomes = [];
        foreach ($tasks as $task) {
            try {
                $result = $this->validator->validate(
                    $task->database,
                    $task->path,
                    $this->options,
                    $task->requireEdition,
                );
                $outcomes[$task->id] = new ValidationOutcome($task->id, $task->database, $result);
            } catch (UpdateFailure $failure) {
                $outcomes[$task->id] = new ValidationOutcome($task->id, $task->database, errorCode: $failure->errorCode);
            }
        }

        return $outcomes;
    }
}
