<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update;

use Trianity\IpAnalyzer\Lookup\LocalDatabase;
use Trianity\IpAnalyzer\Support\Clock;
use Trianity\IpAnalyzer\Update\Parallel\ValidationExecutor;
use Trianity\IpAnalyzer\Update\Parallel\ValidationOutcome;
use Trianity\IpAnalyzer\Update\Parallel\ValidationTask;
use Trianity\IpAnalyzer\Update\Progress\Interrupted;
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
        private readonly InstalledDatabaseInspector $inspector,
        private readonly FreshnessEvaluator $freshness,
        private readonly ValidationExecutor $validations,
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

        $results = $check
            ? $this->check($databases, $targets)
            : $this->update($databases, $targets, $force, $workerCount, $workerTimeout);

        foreach ($results as $result) {
            $this->progress->start(match ($result->status) {
                'updated' => Phase::Done,
                'up_to_date' => Phase::Unchanged,
                'update_available' => Phase::Available,
                'freshness_unknown' => Phase::Unknown,
                'busy' => Phase::Busy,
                default => Phase::Failed,
            }, $result->database);
        }

        return $results;
    }

    /** @param list<string> $databases
     * @param  array<string, string>  $targets
     * @return list<UpdateResult>
     */
    private function check(array $databases, array $targets): array
    {
        $results = [];
        foreach ($databases as $database) {
            $target = $targets[$database];
            $lock = null;
            $inspection = null;
            try {
                $this->progress->start(Phase::Configuration, $database);
                $this->progress->start(Phase::Lock, $database, waitSeconds: $this->options->integer('lock_timeout'));
                $lock = $this->storage->lock($target, true, $this->options);
                $state = $this->storage->state($target);
                if (is_int($state['next_retry_at'] ?? null) && $state['next_retry_at'] > $this->clock->now()) {
                    throw new UpdateFailure('cooldown', $state['next_retry_at']);
                }
                $inspection = $this->inspector->inspect($database, $target);
                $remote = $this->request('HEAD', $database);
                $decision = $this->freshness->evaluate(
                    $this->identity($database, $target),
                    UpdateOptions::EDITIONS[$database],
                    $state,
                    $remote,
                );
                $results[] = new UpdateResult(
                    $database,
                    $decision->status,
                    $inspection->buildEpoch,
                    $decision->status === 'up_to_date' ? $inspection->buildEpoch : null,
                    warning: $remote->validator === null && $remote->etagHash === null && $remote->lastModified === null
                        ? 'remote_version_unverified' : null,
                    localStatus: $inspection->status,
                    localErrorCode: $inspection->errorCode,
                );
            } catch (UpdateFailure $failure) {
                $results[] = new UpdateResult(
                    $database,
                    $failure->errorCode === 'busy' ? 'busy' : 'failed',
                    $inspection?->buildEpoch,
                    errorCode: $failure->errorCode,
                    nextRetryAt: $failure->retryAt,
                    localStatus: $inspection?->status,
                    localErrorCode: $inspection?->errorCode,
                );
            } finally {
                $this->storage->unlock($lock);
            }
        }

        return $results;
    }

    /** @param list<string> $databases
     * @param  array<string, string>  $targets
     * @return list<UpdateResult>
     */
    private function update(array $databases, array $targets, bool $force, int $workers, int $timeout): array
    {
        /** @var array<string, array<string, mixed>> $contexts */
        $contexts = [];
        $tasks = [];
        $ordered = array_values(array_intersect(['country', 'asn'], $databases));
        try {
            foreach ($ordered as $database) {
                $target = $targets[$database];
                $context = [
                    'lock' => null, 'stage' => null, 'state' => [], 'inspection' => null,
                    'identity' => $this->identity($database, $target), 'get' => null,
                    'extracted' => null, 'fingerprint' => null, 'outcome' => null, 'result' => null,
                ];
                try {
                    $this->progress->start(Phase::Configuration, $database);
                    $this->progress->start(Phase::Lock, $database, waitSeconds: $this->options->integer('lock_timeout'));
                    $context['lock'] = $this->storage->lock($target, false, $this->options);
                    $context['state'] = $this->storage->state($target);
                    if (is_int($context['state']['next_retry_at'] ?? null)
                        && $context['state']['next_retry_at'] > $this->clock->now()) {
                        throw new UpdateFailure('cooldown', $context['state']['next_retry_at']);
                    }
                    $context['inspection'] = $this->inspector->inspect($database, $target);
                    $remote = $this->request('HEAD', $database);
                    $decision = $this->freshness->evaluate(
                        $context['identity'], UpdateOptions::EDITIONS[$database], $context['state'], $remote,
                    );
                    if (! $force && ! $decision->replacementRequired() && $context['inspection']->status === 'usable') {
                        $context['result'] = new UpdateResult(
                            $database, 'up_to_date', $context['inspection']->buildEpoch, $context['inspection']->buildEpoch,
                            localStatus: 'usable',
                        );
                        $contexts[$database] = $context;

                        continue;
                    }

                    $context['stage'] = $this->storage->stage($target, $this->options);
                    $context['get'] = $this->request('GET', $database, $context['stage'].'/download');
                    $this->progress->start(Phase::Extract, $database);
                    $context['extracted'] = $this->archive->extract(
                        $context['stage'].'/download', $context['stage'], UpdateOptions::EDITIONS[$database], $this->options,
                    );
                    $context['fingerprint'] = $this->fingerprint($context['extracted']->database);
                    $tasks[] = new ValidationTask('candidate-'.$database, $database, $context['extracted']->database, true);
                } catch (Interrupted $interrupted) {
                    if (is_string($context['stage'])) {
                        $this->storage->cleanup($context['stage']);
                    }
                    $this->storage->unlock($context['lock']);

                    throw $interrupted;
                } catch (UpdateFailure $failure) {
                    $error = $failure->errorCode;
                    if ($failure->retryAt !== null && is_resource($context['lock'])) {
                        try {
                            $context['state']['next_retry_at'] = $failure->retryAt;
                            $this->storage->saveState($target, $context['state']);
                        } catch (UpdateFailure) {
                            $error = 'cooldown_write_failed';
                        }
                    }
                    $context['result'] = $this->failureResult($database, $context, $failure, $error);
                }
                $contexts[$database] = $context;
            }

            if ($tasks !== []) {
                try {
                    $outcomes = $this->validations->execute($tasks, $workers, $timeout);
                    foreach ($tasks as $task) {
                        $contexts[$task->database]['outcome'] = $outcomes[$task->id] ?? null;
                    }
                } catch (UpdateFailure $failure) {
                    foreach ($tasks as $task) {
                        $contexts[$task->database]['result'] ??= $this->failureResult(
                            $task->database, $contexts[$task->database], $failure,
                        );
                    }
                }
            }

            foreach ($ordered as $database) {
                $context = &$contexts[$database];
                if ($context['result'] instanceof UpdateResult) {
                    unset($context);

                    continue;
                }
                $outcome = $context['outcome'];
                if (! $outcome instanceof ValidationOutcome) {
                    $context['result'] = $this->failureResult(
                        $database, $context, new UpdateFailure('validation_worker_protocol'),
                    );
                    unset($context);

                    continue;
                }
                if ($outcome->errorCode !== null || $outcome->result === null) {
                    $context['result'] = $this->failureResult(
                        $database, $context, new UpdateFailure($outcome->errorCode ?? 'validation_worker_protocol'),
                    );
                    unset($context);

                    continue;
                }
                $installed = false;
                try {
                    if ($context['fingerprint'] !== $this->fingerprint($context['extracted']->database)) {
                        throw new UpdateFailure('validation_file_changed');
                    }
                    if ($context['inspection']?->buildEpoch !== null
                        && $outcome->result->buildEpoch < $context['inspection']->buildEpoch) {
                        throw new UpdateFailure('downgrade_rejected');
                    }
                    $this->progress->start(Phase::Install, $database);
                    $this->storage->saveState(
                        $targets[$database],
                        array_replace($context['state'], ['pending' => true]),
                    );
                    $this->storage->install($context['extracted']->database, $targets[$database]);
                    $installed = true;
                    $this->storage->saveNotices($targets[$database], $context['extracted']->notices);
                    $get = $context['get'];
                    $this->storage->saveState($targets[$database], [
                        'identity' => $context['identity'], 'edition' => UpdateOptions::EDITIONS[$database],
                        'validator' => $get->validator, 'etag_hash' => $get->etagHash,
                        'last_modified' => $get->lastModified, 'build_epoch' => $outcome->result->buildEpoch,
                        'sha256' => $outcome->result->sha256, 'installed_at' => $this->clock->now(),
                        'checked_at' => $this->clock->now(), 'pending' => false,
                    ]);
                    $context['result'] = new UpdateResult(
                        $database, 'updated', $context['inspection']?->buildEpoch, $outcome->result->buildEpoch,
                        warning: $get->validator === null ? 'remote_version_unverified' : null,
                        installed: true, localStatus: 'usable', integrityVerified: true,
                    );
                } catch (UpdateFailure $failure) {
                    $context['result'] = new UpdateResult(
                        $database, 'failed', $context['inspection']?->buildEpoch,
                        $installed ? $outcome->result->buildEpoch : null,
                        $installed ? 'installed_metadata_failed' : $failure->errorCode,
                        $installed ? 'database_installed_metadata_incomplete' : null,
                        installed: $installed, localStatus: $installed ? 'usable' : $context['inspection']?->status,
                        localErrorCode: $installed ? null : $context['inspection']?->errorCode,
                        integrityVerified: $installed,
                    );
                }
                unset($context);
            }

            $resultsByDatabase = [];
            foreach ($contexts as $database => $context) {
                $resultsByDatabase[$database] = $context['result'];
            }

            return array_map(fn (string $database): UpdateResult => $resultsByDatabase[$database], $databases);
        } finally {
            foreach ($contexts as $context) {
                if (is_string($context['stage'])) {
                    $this->storage->cleanup($context['stage']);
                }
                $this->storage->unlock($context['lock']);
            }
        }
    }

    /** @param array<string, mixed> $context */
    private function failureResult(string $database, array $context, UpdateFailure $failure, ?string $error = null): UpdateResult
    {
        $inspection = $context['inspection'];

        return new UpdateResult(
            $database, $failure->errorCode === 'busy' ? 'busy' : 'failed', $inspection?->buildEpoch,
            errorCode: $error ?? $failure->errorCode, nextRetryAt: $failure->retryAt,
            localStatus: $inspection?->status, localErrorCode: $inspection?->errorCode,
        );
    }

    private function identity(string $database, string $target): string
    {
        return hash('sha256', $database."\0".$target."\0".$this->options->url($database));
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
                401, 403 => 'authentication_failed', 304 => 'unexpected_not_modified', default => 'remote_http_error',
            });
        }
    }
}
