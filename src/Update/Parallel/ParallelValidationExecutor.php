<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update\Parallel;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;
use Trianity\IpAnalyzer\Update\Progress\Interrupted;
use Trianity\IpAnalyzer\Update\Progress\Phase;
use Trianity\IpAnalyzer\Update\Progress\Progress;
use Trianity\IpAnalyzer\Update\Progress\Snapshot;
use Trianity\IpAnalyzer\Update\UpdateFailure;

final class ParallelValidationExecutor implements ParallelValidationExecutorContract
{
    private const MAX_STDERR_BYTES = 4096;

    public function __construct(
        private readonly WorkerProcessFactory $factory,
        private readonly Progress $progress,
    ) {}

    public function supported(): bool
    {
        return $this->factory->supported();
    }

    public function execute(array $tasks, int $workers, int $timeout): array
    {
        $pending = array_values($tasks);
        $active = [];
        $outcomes = [];
        $failure = null;
        $started = 0;
        try {
            while ($pending !== [] || $active !== []) {
                $this->progress->checkpoint();
                while ($pending !== [] && count($active) < $workers) {
                    $task = array_shift($pending);
                    $process = null;
                    try {
                        $decoder = new ProtocolDecoder($task);
                        $process = $this->factory->create($task, $timeout);
                        $state = (object) [
                            'task' => $task,
                            'process' => $process,
                            'decoder' => $decoder,
                            'stderr' => '',
                        ];
                        $active[$task->id] = $state;
                        $process->start(function (string $type, string $chunk) use ($state): void {
                            if ($type === 'err') {
                                $state->stderr = substr($state->stderr.$chunk, -self::MAX_STDERR_BYTES);

                                return;
                            }
                            foreach ($state->decoder->push($chunk) as $message) {
                                if ($message instanceof Snapshot) {
                                    $this->progress->relay($message);
                                }
                            }
                        });
                    } catch (Interrupted|UpdateFailure $exception) {
                        if ($process instanceof WorkerProcess) {
                            $process->stop();
                        }
                        unset($active[$task->id]);
                        throw $exception;
                    } catch (Throwable) {
                        if ($process instanceof WorkerProcess) {
                            $process->stop();
                        }
                        unset($active[$task->id]);
                        if ($started === 0) {
                            throw new ParallelUnavailable;
                        }
                        throw new UpdateFailure('validation_worker_failed');
                    }
                    $started++;
                    $this->progress->start(Phase::ValidationRunning, $task->database);
                }
                foreach ($active as $id => $state) {
                    try {
                        $state->process->tick();
                    } catch (ProcessTimedOutException) {
                        $failure = new UpdateFailure('validation_worker_timeout');
                        break;
                    } catch (UpdateFailure $exception) {
                        $failure = $exception;
                        break;
                    } catch (Throwable) {
                        $failure = new UpdateFailure('validation_worker_failed');
                        break;
                    }
                    try {
                        $running = $state->process->running();
                    } catch (UpdateFailure $exception) {
                        $failure = $exception;
                        break;
                    } catch (Throwable) {
                        $failure = new UpdateFailure('validation_worker_failed');
                        break;
                    }
                    if (! $running) {
                        try {
                            if (! $state->process->successful()) {
                                throw new UpdateFailure('validation_worker_failed');
                            }
                            $outcome = $state->decoder->finish();
                            $outcomes[$id] = $outcome;
                            $this->progress->start(
                                $outcome->result === null ? Phase::ValidationFailed : Phase::ValidationComplete,
                                $state->task->database,
                            );
                        } catch (UpdateFailure $exception) {
                            $failure ??= $exception;
                        }
                        unset($active[$id]);
                    }
                }
                if ($failure !== null) {
                    throw $failure;
                }
                if ($active !== []) {
                    usleep(10000);
                }
            }
        } finally {
            foreach ($active as $state) {
                $state->process->stop();
            }
        }

        return $outcomes;
    }
}
