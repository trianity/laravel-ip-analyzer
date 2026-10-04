<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Trianity\IpAnalyzer\Lookup\LocalDatabase;
use Trianity\IpAnalyzer\Lookup\ReaderFactory;
use Trianity\IpAnalyzer\Support\Clock;
use Trianity\IpAnalyzer\Update\CandidateValidator;
use Trianity\IpAnalyzer\Update\Progress\Observer;
use Trianity\IpAnalyzer\Update\Progress\Progress;
use Trianity\IpAnalyzer\Update\Progress\Snapshot;
use Trianity\IpAnalyzer\Update\UpdateFailure;
use Trianity\IpAnalyzer\Update\UpdateOptions;

$arguments = $_SERVER['argv'] ?? null;
if (! is_array($arguments) || count($arguments) !== 3
    || ! is_string($arguments[1]) || ! is_file($arguments[1]) || ! is_readable($arguments[1])
    || ! is_string($arguments[2])) {
    exit(64);
}
require $arguments[1];

$decoded = base64_decode(strtr($arguments[2], '-_', '+/'), true);
$payload = is_string($decoded) ? json_decode($decoded, true) : null;
if (! is_array($payload)
    || ! is_string($payload['task'] ?? null) || preg_match('/^[a-z0-9-]{1,64}$/D', $payload['task']) !== 1
    || ! in_array($payload['database'] ?? null, ['country', 'asn'], true)
    || ! is_string($payload['path'] ?? null) || ! str_starts_with($payload['path'], '/')
    || str_contains($payload['path'], "\0") || str_contains($payload['path'], '\\') || str_contains($payload['path'], '://')
    || ! is_bool($payload['requireEdition'] ?? null)
    || ! is_int($payload['maxRecords'] ?? null) || $payload['maxRecords'] < 1
    || ! is_int($payload['maxAgeDays'] ?? null) || $payload['maxAgeDays'] < 0
    || ! is_int($payload['now'] ?? null) || $payload['now'] < 1) {
    exit(64);
}

$write = static function (array $message): void {
    fwrite(STDOUT, json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
    fflush(STDOUT);
};
$base = ['task' => $payload['task'], 'database' => $payload['database']];
$clock = new class($payload['now']) extends Clock
{
    public function __construct(private readonly int $fixed) {}

    public function now(): int
    {
        return $this->fixed;
    }
};
$config = new Repository(['ip-analyzer' => [
    'max_age_days' => $payload['maxAgeDays'],
    'databases' => [$payload['database'] => $payload['path']],
    'update' => array_replace(UpdateOptions::defaults(), ['max_records' => $payload['maxRecords']]),
]]);
$progress = new Progress;
$progress->observe(new class($base, $write) implements Observer
{
    /** @param array{task: string, database: string} $base */
    public function __construct(private readonly array $base, private readonly Closure $write) {}

    public function report(Snapshot $snapshot): void
    {
        ($this->write)(array_merge($this->base, [
            'type' => 'progress',
            'phase' => $snapshot->phase->value,
            'completed' => $snapshot->completed,
            'total' => $snapshot->total,
            'elapsed' => $snapshot->elapsed,
            'eta' => $snapshot->eta,
            'waitSeconds' => $snapshot->waitSeconds,
            'finished' => $snapshot->finished,
        ]));
    }
});

try {
    $validator = new CandidateValidator(
        new LocalDatabase($config, new ReaderFactory, $clock),
        $clock,
        $progress,
    );
    $result = $validator->validate(
        $payload['database'],
        $payload['path'],
        new UpdateOptions($config),
        $payload['requireEdition'],
    );
    $write(array_merge($base, [
        'type' => 'result',
        'ok' => true,
        'buildEpoch' => $result->buildEpoch,
        'sha256' => $result->sha256,
    ]));
    exit(0);
} catch (UpdateFailure $failure) {
    $write(array_merge($base, ['type' => 'result', 'ok' => false, 'errorCode' => $failure->errorCode]));
    exit(0);
} catch (Throwable) {
    $write(array_merge($base, ['type' => 'error', 'errorCode' => 'worker_failed']));
    exit(1);
}
