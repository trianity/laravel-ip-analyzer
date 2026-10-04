<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update;

use Trianity\IpAnalyzer\Lookup\LocalDatabase;
use Trianity\IpAnalyzer\Update\Parallel\ValidationExecutor;
use Trianity\IpAnalyzer\Update\Parallel\ValidationOutcome;
use Trianity\IpAnalyzer\Update\Parallel\ValidationTask;
use Trianity\IpAnalyzer\Update\Progress\Phase;
use Trianity\IpAnalyzer\Update\Progress\Progress;
use Trianity\IpAnalyzer\Update\UpdateConfigurationException as InvalidArgumentException;

final readonly class DatabaseVerifier
{
    public function __construct(
        private UpdateOptions $options,
        private UpdateStorage $storage,
        private LocalDatabase $databases,
        private ValidationExecutor $validations,
        private ValidationOptions $validationOptions,
        private Progress $progress,
    ) {}

    /** @param list<string> $databases
     * @return list<VerificationResult>
     */
    public function run(array $databases = [], mixed $workers = null): array
    {
        $databases = $databases ?: ['country', 'asn'];
        if (! array_is_list($databases) || array_diff($databases, array_keys(UpdateOptions::EDITIONS))) {
            throw new InvalidArgumentException('Database must be country or asn.');
        }
        $databases = array_values(array_unique($databases));
        $workerCount = $this->validationOptions->workers($workers);
        $timeout = $this->validationOptions->timeout();
        $this->databases->validateConfiguration();
        $targets = $this->options->targets();
        $contexts = [];
        $tasks = [];
        try {
            foreach (array_values(array_intersect(['country', 'asn'], $databases)) as $database) {
                $this->progress->start(Phase::Configuration, $database);
                $context = ['lock' => null, 'fingerprint' => null, 'result' => null];
                try {
                    $this->progress->start(Phase::Lock, $database, waitSeconds: $this->options->integer('lock_timeout'));
                    $context['lock'] = $this->storage->lock($targets[$database], true, $this->options);
                    $context['fingerprint'] = $this->fingerprint($targets[$database]);
                    $tasks[] = new ValidationTask('installed-'.$database, $database, $targets[$database], false);
                } catch (UpdateFailure $failure) {
                    $context['result'] = new VerificationResult(
                        $database, $failure->errorCode === 'busy' ? 'busy' : 'failed', errorCode: $failure->errorCode,
                    );
                }
                $contexts[$database] = $context;
            }

            if ($tasks !== []) {
                try {
                    $outcomes = $this->validations->execute($tasks, $workerCount, $timeout);
                    foreach ($tasks as $task) {
                        $outcome = $outcomes[$task->id] ?? null;
                        $contexts[$task->database]['result'] = $this->result(
                            $task, $outcome, $contexts[$task->database]['fingerprint'], $targets[$task->database],
                        );
                    }
                } catch (UpdateFailure $failure) {
                    foreach ($tasks as $task) {
                        $contexts[$task->database]['result'] = new VerificationResult(
                            $task->database, 'failed', errorCode: $failure->errorCode,
                        );
                    }
                }
            }

            $results = array_map(
                fn (string $database): VerificationResult => $contexts[$database]['result'],
                $databases,
            );
            foreach ($results as $result) {
                $this->progress->start(match ($result->status) {
                    'verified' => Phase::Done, 'busy' => Phase::Busy, default => Phase::Failed,
                }, $result->database);
            }

            return $results;
        } finally {
            foreach ($contexts as $context) {
                $this->storage->unlock($context['lock']);
            }
        }
    }

    /** @param array{dev: int, ino: int, size: int, mtime: int, ctime: int}|null $before */
    private function result(ValidationTask $task, ?ValidationOutcome $outcome, ?array $before, string $path): VerificationResult
    {
        if ($outcome === null) {
            return new VerificationResult($task->database, 'failed', errorCode: 'validation_worker_protocol');
        }
        if ($outcome->errorCode !== null || $outcome->result === null) {
            return new VerificationResult($task->database, 'failed', errorCode: $outcome->errorCode ?? 'validation_worker_protocol');
        }
        if ($before !== $this->fingerprint($path)) {
            return new VerificationResult($task->database, 'failed', errorCode: 'validation_file_changed');
        }

        return new VerificationResult(
            $task->database, 'verified', $outcome->result->buildEpoch, $outcome->result->sha256,
            integrityVerified: true,
        );
    }

    /** @return array{dev: int, ino: int, size: int, mtime: int, ctime: int}|null */
    private function fingerprint(string $path): ?array
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (! is_array($stat)) {
            return null;
        }

        return [
            'dev' => $stat['dev'], 'ino' => $stat['ino'], 'size' => $stat['size'],
            'mtime' => $stat['mtime'], 'ctime' => $stat['ctime'],
        ];
    }
}
