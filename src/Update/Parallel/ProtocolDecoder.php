<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update\Parallel;

use JsonException;
use Trianity\IpAnalyzer\Update\Progress\Database;
use Trianity\IpAnalyzer\Update\Progress\Phase;
use Trianity\IpAnalyzer\Update\Progress\Snapshot;
use Trianity\IpAnalyzer\Update\UpdateFailure;
use Trianity\IpAnalyzer\Update\ValidatedDatabase;

final class ProtocolDecoder
{
    private const MAX_LINE_BYTES = 65536;

    private string $buffer = '';

    private ?ValidationOutcome $outcome = null;

    public function __construct(private readonly ValidationTask $task) {}

    /** @return list<Snapshot|ValidationOutcome> */
    public function push(string $chunk): array
    {
        $this->buffer .= $chunk;
        if (strlen($this->buffer) > self::MAX_LINE_BYTES && ! str_contains($this->buffer, "\n")) {
            throw new UpdateFailure('validation_worker_protocol');
        }
        $messages = [];
        while (($position = strpos($this->buffer, "\n")) !== false) {
            $line = substr($this->buffer, 0, $position);
            $this->buffer = substr($this->buffer, $position + 1);
            if ($line === '' || strlen($line) > self::MAX_LINE_BYTES) {
                throw new UpdateFailure('validation_worker_protocol');
            }
            $messages[] = $this->decode($line);
        }
        if (strlen($this->buffer) > self::MAX_LINE_BYTES) {
            throw new UpdateFailure('validation_worker_protocol');
        }

        return $messages;
    }

    public function finish(): ValidationOutcome
    {
        if ($this->buffer !== '' || $this->outcome === null) {
            throw new UpdateFailure('validation_worker_protocol');
        }

        return $this->outcome;
    }

    private function decode(string $line): Snapshot|ValidationOutcome
    {
        try {
            $data = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new UpdateFailure('validation_worker_protocol');
        }
        if (! is_array($data) || ($data['task'] ?? null) !== $this->task->id
            || ($data['database'] ?? null) !== $this->task->database || ! is_string($data['type'] ?? null)) {
            throw new UpdateFailure('validation_worker_protocol');
        }
        if ($this->outcome !== null) {
            throw new UpdateFailure('validation_worker_protocol');
        }

        if ($data['type'] === 'progress') {
            return $this->progress($data);
        }
        if ($data['type'] === 'error') {
            throw new UpdateFailure('validation_worker_failed');
        }
        if ($data['type'] !== 'result' || ! is_bool($data['ok'] ?? null)) {
            throw new UpdateFailure('validation_worker_protocol');
        }
        if ($data['ok']) {
            if (! is_int($data['buildEpoch'] ?? null) || $data['buildEpoch'] <= 0
                || ! is_string($data['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $data['sha256']) !== 1) {
                throw new UpdateFailure('validation_worker_protocol');
            }
            $result = new ValidatedDatabase($data['buildEpoch'], $data['sha256']);
            $this->outcome = new ValidationOutcome($this->task->id, $this->task->database, $result);
        } else {
            if (! is_string($data['errorCode'] ?? null) || preg_match('/^[a-z0-9_]{1,64}$/D', $data['errorCode']) !== 1) {
                throw new UpdateFailure('validation_worker_protocol');
            }
            $this->outcome = new ValidationOutcome($this->task->id, $this->task->database, errorCode: $data['errorCode']);
        }

        return $this->outcome;
    }

    /** @param array<string, mixed> $data */
    private function progress(array $data): Snapshot
    {
        $phase = is_string($data['phase'] ?? null) ? Phase::tryFrom($data['phase']) : null;
        $completed = $data['completed'] ?? null;
        $total = $data['total'] ?? null;
        $elapsed = $data['elapsed'] ?? null;
        $eta = $data['eta'] ?? null;
        $wait = $data['waitSeconds'] ?? null;
        $finished = $data['finished'] ?? null;
        $allowedPhases = [$this->task->requireEdition ? Phase::CandidateValidation : Phase::LocalValidation, Phase::Hash];
        if ($phase === null || ! in_array($phase, $allowedPhases, true)
            || ! is_int($completed) || $completed < 0
            || ($total !== null && (! is_int($total) || $total < 0))
            || ! $this->finiteNumber($elapsed)
            || ($eta !== null && ! $this->finiteNumber($eta))
            || ($wait !== null && (! is_int($wait) || $wait < 0))
            || ! is_bool($finished)) {
            throw new UpdateFailure('validation_worker_protocol');
        }

        return new Snapshot(
            $phase,
            Database::from($this->task->database),
            $completed,
            $total,
            (float) $elapsed,
            $eta === null ? null : (float) $eta,
            $wait,
            $finished,
        );
    }

    private function finiteNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0;
    }
}
