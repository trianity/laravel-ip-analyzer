<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update\Parallel;

interface ParallelValidationExecutorContract extends ValidationExecutor
{
    public function supported(): bool;
}
