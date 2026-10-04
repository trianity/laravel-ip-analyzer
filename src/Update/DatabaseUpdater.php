<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update;

use Trianity\IpAnalyzer\Lookup\LocalDatabase;
use Trianity\IpAnalyzer\Support\Clock;
use Trianity\IpAnalyzer\Update\Parallel\ValidationScheduler;
use Trianity\IpAnalyzer\Update\Parallel\ValidationTask;
use Trianity\IpAnalyzer\Update\Progress\Phase;
use Trianity\IpAnalyzer\Update\Progress\Progress;
use Trianity\IpAnalyzer\Update\UpdateConfigurationException as InvalidArgumentException;

final class DatabaseUpdater
{
    public function __construct(
        private readonly UpdateOptions $options,
        private readonly Transport $transport,
        private readonly TarArchive $archive,
        private readonly UpdateStorage $storage,
        private readonly Clock $clock,
        private readonly Sleeper $sleeper,
        private readonly LocalDatabase $databases,
        private readonly ValidationScheduler $validations,
        private readonly ValidationOptions $validationOptions,
        private readonly Progress $progress = new Progress,
    ) {}

    /** @param list<string> $databases
     * @return list<UpdateResult>
     */
    public function run(array $databases = [], bool $force = false, bool $check = false, mixed $workers = null): array
    {
        $databases = $databases ?: ['country', 'asn'];
        if (! array_is_list($databases) || array_diff($databases, array_keys(UpdateOptions::EDITIONS))) {
            throw new InvalidArgumentException('Database must be country or asn.');
        }
        $databases = array_values(array_unique($databases));
        $workerCount = $this->validationOptions->workers($workers);
        $workerTimeout = $this->validationOptions->timeout();
        $this->databases->validateConfiguration();
        $this->options->credentials();
        $targets = $this->options->targets();
        if ($check && $workerCount > 1 && count($databases) > 1) {
            return $this->checkMany($databases, $targets, $workerCount, $workerTimeout);
        }
        $results = [];
        foreach ($databases as $database) {
            $this->progress->start(Phase::Configuration, $database);
            $result = $this->one($database, $targets[$database], $force, $check, $workerTimeout);
            $results[] = $result;
            $this->progress->start(match ($result->status) {
                'updated' => Phase::Done, 'up_to_date' => Phase::Unchanged,
                'update_available' => Phase::Available, 'busy' => Phase::Busy, default => Phase::Failed,
            }, $database);
        }

        return $results;
    }

    /**
     * @param  list<string>  $databases
     * @param  array<string, string>  $targets
     * @return list<UpdateResult>
     */
    private function checkMany(array $databases, array $targets, int $workers, int $timeout): array
    {
        $contexts = [];
        $tasks = [];
        try {
            foreach ($databases as $database) {
                $target = $targets[$database];
                $this->progress->start(Phase::Configuration, $database);
                $context = ['lock' => null, 'state' => [], 'fingerprint' => null, 'outcome' => null, 'result' => null];
                try {
                    $this->progress->start(Phase::Lock, $database, waitSeconds: $this->options->integer('lock_timeout'));
                    $context['lock'] = $this->storage->lock($target, true, $this->options);
                    $context['state'] = $this->storage->state($target);
                    if (is_int($context['state']['next_retry_at'] ?? null)
                        && $context['state']['next_retry_at'] > $this->clock->now()) {
                        throw new UpdateFailure('cooldown', $context['state']['next_retry_at']);
                    }
                    if (is_file($target)) {
                        $context['fingerprint'] = $this->fingerprint($target);
                        $tasks[] = new ValidationTask('validation-'.$database, $database, $target, false);
                    }
                } catch (UpdateFailure $failure) {
                    $context['result'] = new UpdateResult(
                        $database,
                        $failure->errorCode === 'busy' ? 'busy' : 'failed',
                        errorCode: $failure->errorCode,
                        nextRetryAt: $failure->retryAt,
                    );
                }
                $contexts[$database] = $context;
            }

            if ($tasks !== []) {
                try {
                    $outcomes = $this->validations->execute($tasks, $workers, $timeout);
                    foreach ($tasks as $task) {
                        if (! isset($outcomes[$task->id])) {
                            throw new UpdateFailure('validation_worker_protocol');
                        }
                        $contexts[$task->database]['outcome'] = $outcomes[$task->id];
                    }
                } catch (UpdateFailure $failure) {
                    foreach ($tasks as $task) {
                        $contexts[$task->database]['result'] ??= new UpdateResult(
                            $task->database,
                            'failed',
                            errorCode: $failure->errorCode,
                        );
                        $this->progress->start(Phase::Failed, $task->database);
                    }
                }
            }

            $results = [];
            foreach ($databases as $database) {
                $context = $contexts[$database];
                $result = $context['result'] instanceof UpdateResult ? $context['result'] : null;
                if ($result === null) {
                    $outcome = $context['outcome'];
                    $old = null;
                    if ($outcome !== null && $outcome->result !== null) {
                        if ($context['fingerprint'] !== $this->fingerprint($targets[$database])) {
                            $result = new UpdateResult($database, 'failed', errorCode: 'validation_file_changed');
                        } else {
                            $old = $outcome->result;
                        }
                    }
                    if ($result === null) {
                        $result = $this->checkResult($database, $targets[$database], $context['state'], $old);
                    }
                }
                $results[] = $result;
                $this->progress->start(match ($result->status) {
                    'up_to_date' => Phase::Unchanged,
                    'update_available' => Phase::Available,
                    'busy' => Phase::Busy,
                    default => Phase::Failed,
                }, $database);
            }

            return $results;
        } finally {
            foreach ($contexts as $context) {
                $this->storage->unlock($context['lock']);
            }
        }
    }

    /** @param array<string, mixed> $state */
    private function checkResult(string $source, string $target, array $state, ?ValidatedDatabase $old): UpdateResult
    {
        try {
            $identity = hash('sha256', $source."\0".$target."\0".$this->options->url($source));
            $head = $this->request('HEAD', $source);
            $same = $head->validator !== null && $old !== null && ! ($state['pending'] ?? false)
                && ($state['identity'] ?? null) === $identity && $head->matches($state)
                && ($state['sha256'] ?? null) === $old->sha256 && ($state['build_epoch'] ?? null) === $old->buildEpoch;

            return new UpdateResult(
                $source,
                $same ? 'up_to_date' : 'update_available',
                $old?->buildEpoch,
                $same ? $old?->buildEpoch : null,
                warning: $head->validator === null ? 'remote_version_unverified' : null,
            );
        } catch (UpdateFailure $failure) {
            return new UpdateResult(
                $source,
                $failure->errorCode === 'busy' ? 'busy' : 'failed',
                $old?->buildEpoch,
                errorCode: $failure->errorCode,
                nextRetryAt: $failure->retryAt,
            );
        }
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
            'dev' => $stat['dev'],
            'ino' => $stat['ino'],
            'size' => $stat['size'],
            'mtime' => $stat['mtime'],
            'ctime' => $stat['ctime'],
        ];
    }

    private function one(string $source, string $target, bool $force, bool $check, int $workerTimeout): UpdateResult
    {
        $lock = null;
        $stage = null;
        $old = null;
        $new = null;
        $installed = false;
        $state = [];
        try {
            $this->progress->start(Phase::Lock, $source, waitSeconds: $this->options->integer('lock_timeout'));
            $lock = $this->storage->lock($target, $check, $this->options);
            $state = $this->storage->state($target);
            if (is_int($state['next_retry_at'] ?? null) && $state['next_retry_at'] > $this->clock->now()) {
                throw new UpdateFailure('cooldown', $state['next_retry_at']);
            }
            if (is_file($target)) {
                $old = $this->validateOne(new ValidationTask('local-'.$source, $source, $target, false), $workerTimeout);
            }
            $identity = hash('sha256', $source."\0".$target."\0".$this->options->url($source));
            $head = $this->request('HEAD', $source);
            $same = $head->validator !== null && $old !== null && ! ($state['pending'] ?? false)
                && ($state['identity'] ?? null) === $identity && $head->matches($state)
                && ($state['sha256'] ?? null) === $old->sha256 && ($state['build_epoch'] ?? null) === $old->buildEpoch;
            if (! $force && $same) {
                return new UpdateResult($source, 'up_to_date', $old->buildEpoch, $old->buildEpoch);
            }
            if ($check) {
                return new UpdateResult($source, 'update_available', $old?->buildEpoch,
                    warning: $head->validator === null ? 'remote_version_unverified' : null);
            }
            $stage = $this->storage->stage($target, $this->options);
            $get = $this->request('GET', $source, $stage.'/download');
            $this->progress->start(Phase::Extract, $source);
            $extracted = $this->archive->extract($stage.'/download', $stage, UpdateOptions::EDITIONS[$source], $this->options);
            $new = $this->validateOne(
                new ValidationTask('candidate-'.$source, $source, $extracted->database, true),
                $workerTimeout,
            );
            if ($new === null) {
                throw new UpdateFailure('invalid_database');
            }
            if ($old !== null && $new->buildEpoch < $old->buildEpoch) {
                throw new UpdateFailure('downgrade_rejected');
            }
            $changed = $old === null || $new->sha256 !== $old->sha256;
            $this->progress->start(Phase::Install, $source);
            // No cancellation checkpoint between rename and metadata completion.
            // A crash or post-rename metadata error cannot leave an apparently complete state.
            $this->storage->saveState($target, ['pending' => true]);
            if ($changed) {
                $this->storage->install($extracted->database, $target);
                $installed = true;
            }
            $this->storage->saveNotices($target, $extracted->notices);
            $this->storage->saveState($target, [
                'identity' => $identity, 'edition' => UpdateOptions::EDITIONS[$source],
                // Only GET validators identify the bytes actually installed. No HEAD/GET race.
                'validator' => $get->validator, 'etag_hash' => $get->etagHash, 'last_modified' => $get->lastModified, 'build_epoch' => $new->buildEpoch, 'sha256' => $new->sha256,
                'installed_at' => $changed ? $this->clock->now() : (is_int($state['installed_at'] ?? null) ? $state['installed_at'] : null),
                'checked_at' => $this->clock->now(), 'pending' => false,
            ]);

            return new UpdateResult($source, $changed ? 'updated' : 'up_to_date', $old?->buildEpoch, $new->buildEpoch,
                warning: $get->validator === null ? 'remote_version_unverified' : null, installed: $installed);
        } catch (UpdateFailure $e) {
            $error = $e->errorCode;
            if (! $check && $e->retryAt !== null && is_resource($lock)) {
                try {
                    $state['next_retry_at'] = $e->retryAt;
                    $this->storage->saveState($target, $state);
                } catch (UpdateFailure) {
                    $error = 'cooldown_write_failed';
                }
            }

            return new UpdateResult($source, $e->errorCode === 'busy' ? 'busy' : 'failed', $old?->buildEpoch,
                $installed ? $new?->buildEpoch : null, $installed ? 'installed_metadata_failed' : $error,
                $installed ? 'database_installed_metadata_incomplete' : null, $e->retryAt, $installed);
        } finally {
            if ($stage !== null) {
                $this->storage->cleanup($stage);
            }
            $this->storage->unlock($lock);
        }
    }

    private function validateOne(ValidationTask $task, int $timeout): ?ValidatedDatabase
    {
        $outcome = $this->validations->execute([$task], 1, $timeout)[$task->id] ?? null;
        if ($outcome === null) {
            throw new UpdateFailure('validation_worker_protocol');
        }
        if ($outcome->errorCode !== null) {
            if ($task->requireEdition) {
                throw new UpdateFailure($outcome->errorCode);
            }

            return null;
        }

        return $outcome->result;
    }

    private function request(string $method, string $source, ?string $sink = null): RemoteResponse
    {
        for ($attempt = 0; ; $attempt++) {
            $this->progress->start($method === 'HEAD' ? Phase::Head : Phase::Download, $source, waitSeconds: $this->options->integer('timeout'));
            $response = $this->transport->request($method, $this->options->url($source), $this->options, $sink);
            $this->progress->checkpoint();
            if ($response->status === 200 || ($method === 'HEAD' && in_array($response->status, [405, 501], true))) {
                return $response->status === 200 ? $response : new RemoteResponse(200);
            }
            if ($response->status === 429 || in_array($response->status, [500, 502, 503, 504], true)) {
                $now = $this->clock->now();
                $next = max($now + 1, $response->retryAt ?? $now + $this->options->integer('retry_delay') * (2 ** $attempt));
                $delay = $next - $now;
                if ($attempt >= $this->options->integer('retries') || $delay > $this->options->integer('max_retry_wait')) {
                    throw new UpdateFailure($response->status === 429 ? 'rate_limited' : 'remote_unavailable', $next);
                }
                $this->progress->start(Phase::Retry, $source, waitSeconds: $delay);
                $this->sleeper->pause($delay);
                $this->progress->checkpoint();

                continue;
            }
            throw new UpdateFailure(match ($response->status) {
                401, 403 => 'authentication_failed',
                304 => 'unexpected_not_modified',
                default => 'remote_http_error',
            });
        }
    }
}
