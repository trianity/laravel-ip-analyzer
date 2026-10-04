<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update\Parallel;

use Symfony\Component\Process\Process;

final class SymfonyWorkerProcess implements WorkerProcess
{
    public function __construct(private readonly Process $process) {}

    public function start(callable $output): void
    {
        $this->process->start(static function (string $type, string $chunk) use ($output): void {
            $output($type === Process::OUT ? 'out' : 'err', $chunk);
        });
    }

    public function tick(): void
    {
        $this->process->checkTimeout();
        $this->process->isRunning();
        $this->process->clearOutput();
        $this->process->clearErrorOutput();
    }

    public function running(): bool
    {
        return $this->process->isRunning();
    }

    public function successful(): bool
    {
        return $this->process->isSuccessful();
    }

    public function stop(): void
    {
        if ($this->process->isRunning()) {
            $this->process->stop(1);
        }
    }
}
