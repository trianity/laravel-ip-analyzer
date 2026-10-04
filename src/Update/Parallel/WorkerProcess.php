<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update\Parallel;

interface WorkerProcess
{
    /** @param callable(string, string): void $output */
    public function start(callable $output): void;

    public function tick(): void;

    public function running(): bool;

    public function successful(): bool;

    public function stop(): void;
}
