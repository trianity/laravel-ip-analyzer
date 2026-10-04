<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update\Parallel;

interface WorkerProcessFactory
{
    public function supported(): bool;

    public function create(ValidationTask $task, int $timeout): WorkerProcess;
}
