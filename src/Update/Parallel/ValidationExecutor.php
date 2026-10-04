<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update\Parallel;

interface ValidationExecutor
{
    /**
     * @param  list<ValidationTask>  $tasks
     * @return array<string, ValidationOutcome>
     */
    public function execute(array $tasks, int $workers, int $timeout): array;
}
