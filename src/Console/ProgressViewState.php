<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Console;

use Trianity\IpAnalyzer\Update\Progress\Database;
use Trianity\IpAnalyzer\Update\Progress\Phase;
use Trianity\IpAnalyzer\Update\Progress\Snapshot;

/** @internal Keeps the latest worker/main-process state independently of rendering. */
final class ProgressViewState
{
    /** @var array<string, Snapshot> */
    private array $snapshots = [];

    /** @param list<Database> $databases */
    public function __construct(private readonly array $databases)
    {
        foreach ($databases as $database) {
            $this->snapshots[$database->value] = new Snapshot(Phase::Configuration, $database, 0, null, 0, null);
        }
    }

    /** @return list<array{previous: Snapshot, current: Snapshot}> */
    public function update(Snapshot $snapshot): array
    {
        $targets = $snapshot->database === null ? $this->databases : [$snapshot->database];
        $changes = [];
        foreach ($targets as $database) {
            if (! isset($this->snapshots[$database->value])) {
                continue;
            }
            $current = new Snapshot($snapshot->phase, $database, $snapshot->completed, $snapshot->total, $snapshot->elapsed, $snapshot->eta, $snapshot->waitSeconds, $snapshot->finished);
            $changes[] = ['previous' => $this->snapshots[$database->value], 'current' => $current];
            $this->snapshots[$database->value] = $current;
        }

        return $changes;
    }

    /** @return list<Snapshot> */
    public function snapshots(): array
    {
        return array_values($this->snapshots);
    }

    public function terminate(Phase $phase): void
    {
        foreach ($this->snapshots as $database => $snapshot) {
            if (self::terminal($snapshot->phase)) {
                continue;
            }
            $this->snapshots[$database] = new Snapshot($phase, $snapshot->database, $snapshot->completed, $snapshot->total, $snapshot->elapsed, null, finished: true);
        }
    }

    private static function terminal(Phase $phase): bool
    {
        return in_array($phase, [Phase::Done, Phase::Unchanged, Phase::Available, Phase::Failed, Phase::Busy, Phase::Interrupted], true);
    }
}
