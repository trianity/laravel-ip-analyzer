<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update\Progress;

final class Progress
{
    private Observer $observer;

    private bool $enabled = false;

    private bool $cancelled = false;

    private Phase $phase = Phase::Configuration;

    private ?Database $database = null;

    private ?int $total = null;

    private ?int $waitSeconds = null;

    private float $started = 0;

    private float $last = 0;

    private int $completed = 0;

    private int $samples = 0;

    private ?float $speed = null;

    public function __construct(private readonly MonotonicClock $clock = new MonotonicClock)
    {
        $this->observer = new NullObserver;
    }

    public function observe(Observer $observer): void
    {
        $this->observer = $observer;
        $this->enabled = ! $observer instanceof NullObserver;
        $this->cancelled = false;
    }

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function checkpoint(): void
    {
        if ($this->cancelled) {
            throw new Interrupted;
        }
    }

    public function relay(Snapshot $snapshot): void
    {
        $this->checkpoint();
        if ($this->enabled) {
            $this->observer->report($snapshot);
        }
        $this->checkpoint();
    }

    public function start(Phase $phase, ?string $database = null, ?int $total = null, ?int $waitSeconds = null): void
    {
        $this->checkpoint();
        if (! $this->enabled) {
            return;
        }
        $this->phase = $phase;
        $this->database = $database === null ? null : Database::from($database);
        $this->total = $total;
        $this->waitSeconds = $waitSeconds;
        $this->started = $this->last = $this->clock->now();
        $this->completed = $this->samples = 0;
        $this->speed = null;
        $this->observer->report(new Snapshot($phase, $this->database, 0, $total, 0, null, $waitSeconds));
        $this->checkpoint();
    }

    public function advance(int $completed, ?int $total = null, bool $force = false): void
    {
        $this->checkpoint();
        if (! $this->enabled) {
            return;
        }
        $now = $this->clock->now();
        $delta = max(0.0, $now - $this->last);
        if (! $force && $delta < .25) {
            return;
        }
        if ($total !== null) {
            $this->total = $total;
        }
        $elapsed = max(0.0, $now - $this->started);
        $gain = max(0, $completed - $this->completed);
        if ($delta > 0) {
            $rate = $gain / $delta;
            $this->speed = $this->speed === null ? $rate : .3 * $rate + .7 * $this->speed;
            $this->samples++;
        }
        $eta = null;
        if ($this->total !== null && $this->total > 0 && $completed >= $this->total) {
            $eta = 0.0;
        } elseif ($this->total !== null && $elapsed >= 1 && $this->samples >= 2 && $gain > 0 && $this->speed > 0) {
            $estimate = max(0, $this->total - $completed) / $this->speed;
            if (is_finite($estimate)) {
                $eta = $estimate;
            }
        }
        $this->last = $now;
        $this->completed = $completed;
        $this->observer->report(new Snapshot($this->phase, $this->database, $completed, $this->total, $elapsed, $eta, $this->waitSeconds, $force));
        $this->checkpoint();
    }
}
