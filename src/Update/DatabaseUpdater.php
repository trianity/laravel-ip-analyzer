<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update;

use Trianity\IpAnalyzer\Lookup\LocalDatabase;
use Trianity\IpAnalyzer\Support\Clock;
use Trianity\IpAnalyzer\Update\UpdateConfigurationException as InvalidArgumentException;

final class DatabaseUpdater
{
    public function __construct(
        private readonly UpdateOptions $options,
        private readonly Transport $transport,
        private readonly TarArchive $archive,
        private readonly CandidateValidator $validator,
        private readonly UpdateStorage $storage,
        private readonly Clock $clock,
        private readonly Sleeper $sleeper,
        private readonly LocalDatabase $databases,
    ) {}

    /** @param list<string> $databases
     * @return list<UpdateResult>
     */
    public function run(array $databases = [], bool $force = false, bool $check = false): array
    {
        $databases = $databases ?: ['country', 'asn'];
        if (! array_is_list($databases) || array_diff($databases, array_keys(UpdateOptions::EDITIONS))) {
            throw new InvalidArgumentException('Database must be country or asn.');
        }
        $this->databases->validateConfiguration();
        $this->options->credentials();
        $targets = $this->options->targets();
        $results = [];
        foreach (array_unique($databases) as $database) {
            $results[] = $this->one($database, $targets[$database], $force, $check);
        }

        return $results;
    }

    private function one(string $source, string $target, bool $force, bool $check): UpdateResult
    {
        $lock = null;
        $stage = null;
        $old = null;
        $new = null;
        $installed = false;
        $state = [];
        try {
            $lock = $this->storage->lock($target, $check, $this->options);
            $state = $this->storage->state($target);
            if (is_int($state['next_retry_at'] ?? null) && $state['next_retry_at'] > $this->clock->now()) {
                throw new UpdateFailure('cooldown', $state['next_retry_at']);
            }
            if (is_file($target)) {
                try {
                    $old = $this->validator->validate($source, $target, $this->options);
                } catch (UpdateFailure) { /* Invalid local files must be repaired, not skipped. */
                }
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
            $extracted = $this->archive->extract($stage.'/download', $stage, UpdateOptions::EDITIONS[$source], $this->options);
            $new = $this->validator->validate($source, $extracted->database, $this->options);
            if ($old !== null && $new->buildEpoch < $old->buildEpoch) {
                throw new UpdateFailure('downgrade_rejected');
            }
            $changed = $old === null || $new->sha256 !== $old->sha256;
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

    private function request(string $method, string $source, ?string $sink = null): RemoteResponse
    {
        for ($attempt = 0; ; $attempt++) {
            $response = $this->transport->request($method, $this->options->url($source), $this->options, $sink);
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
                $this->sleeper->pause($delay);

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
