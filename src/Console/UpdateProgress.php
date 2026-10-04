<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Console;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\Console\Terminal;
use Trianity\IpAnalyzer\Support\Messages;
use Trianity\IpAnalyzer\Update\Progress\Database;
use Trianity\IpAnalyzer\Update\Progress\MonotonicClock;
use Trianity\IpAnalyzer\Update\Progress\Observer;
use Trianity\IpAnalyzer\Update\Progress\Phase;
use Trianity\IpAnalyzer\Update\Progress\Snapshot;

final class UpdateProgress implements Observer
{
    private const INTERACTIVE_REFRESH_SECONDS = 1.0;

    private const PLAIN_REFRESH_SECONDS = 15.0;

    private readonly Messages $messages;

    private readonly MonotonicClock $clock;

    private readonly bool $interactive;

    private readonly int $width;

    private readonly ProgressViewState $state;

    private float $lastInteractiveRender;

    /** @var array<string, float> */
    private array $lastPlainRender = [];

    /** @var array<string, string> */
    private array $lastPlainRows = [];

    private string $lastBlock = '';

    private int $renderedLines = 0;

    private bool $closed = false;

    /** @param list<string> $databases */
    public function __construct(
        private readonly OutputInterface $output,
        ?Messages $messages = null,
        ?MonotonicClock $clock = null,
        ?bool $interactive = null,
        ?int $width = null,
        array $databases = ['country', 'asn'],
    ) {
        $this->messages = $messages ?? app(Messages::class);
        $this->clock = $clock ?? new MonotonicClock;
        $this->interactive = $interactive ?? self::supportsInteractiveOutput($output);
        $this->width = max(12, $width ?? (new Terminal)->getWidth());
        $requested = $databases === [] ? ['country', 'asn'] : $databases;
        $selected = array_values(array_filter(
            [Database::Country, Database::Asn],
            fn (Database $database): bool => in_array($database->value, $requested, true),
        ));
        $this->state = new ProgressViewState($selected === [] ? [Database::Country, Database::Asn] : $selected);
        $this->lastInteractiveRender = $this->clock->now();
        $this->output->writeln($this->messages->get('progress.start'), OutputInterface::OUTPUT_RAW);
        $this->renderInitial();
    }

    public function report(Snapshot $snapshot): void
    {
        if ($this->closed) {
            return;
        }
        $now = $this->clock->now();
        $changes = $this->state->update($snapshot);
        $immediate = false;
        foreach ($changes as $change) {
            if ($change['previous']->phase !== $change['current']->phase || $change['current']->finished) {
                $immediate = true;
                break;
            }
        }

        if ($this->interactive) {
            if ($immediate || $now - $this->lastInteractiveRender >= self::INTERACTIVE_REFRESH_SECONDS) {
                $this->renderBlock();
                $this->lastInteractiveRender = $now;
            }

            return;
        }

        foreach ($changes as $change) {
            $database = $change['current']->database;
            if ($database === null) {
                continue;
            }
            $key = $database->value;
            if ($change['previous']->phase !== $change['current']->phase || $change['current']->finished
                || $now - ($this->lastPlainRender[$key] ?? -INF) >= self::PLAIN_REFRESH_SECONDS) {
                $this->renderPlain($change['current'], $now);
            }
        }
    }

    public function complete(int $exit): void
    {
        if ($this->closed || $exit === 0) {
            return;
        }
        $this->state->terminate(match ($exit) {
            130 => Phase::Interrupted,
            3 => Phase::Busy,
            default => Phase::Failed,
        });
        if ($this->interactive) {
            $this->renderBlock();

            return;
        }
        foreach ($this->state->snapshots() as $snapshot) {
            $this->renderPlain($snapshot, $this->clock->now());
        }
    }

    public function finish(int $exit, float $elapsed): void
    {
        $this->complete($exit);
        $this->endLine();
        $this->output->writeln(self::summary($exit, $elapsed, $this->messages), OutputInterface::OUTPUT_RAW);
    }

    public static function summary(int $exit, float $elapsed, ?Messages $messages = null): string
    {
        $messages ??= app(Messages::class);
        $label = match ($exit) {
            0 => 'done', 130 => 'interrupted', 3 => 'busy', default => 'failed'
        };

        return self::duration($messages, 'summary', $elapsed, ['status' => $messages->get('phases.'.$label)]);
    }

    public function endLine(): void
    {
        $this->closed = true;
    }

    private function renderInitial(): void
    {
        if ($this->interactive) {
            $this->renderBlock();
            $this->lastInteractiveRender = $this->clock->now();

            return;
        }
        $now = $this->clock->now();
        foreach ($this->state->snapshots() as $snapshot) {
            $this->renderPlain($snapshot, $now);
        }
    }

    private function renderBlock(): void
    {
        $rows = array_map(fn (Snapshot $snapshot): string => $this->bounded($this->row($snapshot, true)), $this->state->snapshots());
        $block = implode("\n", $rows);
        if ($block === $this->lastBlock) {
            return;
        }
        if ($this->renderedLines > 0) {
            $this->output->write(sprintf("\033[%dA", $this->renderedLines), false, OutputInterface::OUTPUT_RAW);
        }
        foreach ($rows as $row) {
            $this->output->write("\033[2K".$row.PHP_EOL, false, OutputInterface::OUTPUT_RAW);
        }
        $this->lastBlock = $block;
        $this->renderedLines = count($rows);
    }

    private function renderPlain(Snapshot $snapshot, float $now): void
    {
        $database = $snapshot->database;
        if ($database === null) {
            return;
        }
        $key = $database->value;
        $row = $this->row($snapshot);
        if (($this->lastPlainRows[$key] ?? null) === $row) {
            return;
        }
        $this->output->writeln($row, OutputInterface::OUTPUT_RAW);
        $this->lastPlainRows[$key] = $row;
        $this->lastPlainRender[$key] = $now;
    }

    private function row(Snapshot $snapshot, bool $compact = false): string
    {
        $name = $this->messages->get('databases.'.$snapshot->database->value);
        $parts = [$this->messages->get('progress.phase', [
            'database' => $name,
            'phase' => $this->messages->get(($compact ? 'progress.short_phases.' : 'phases.').$snapshot->phase->value),
        ])];
        if (in_array($snapshot->phase, [Phase::Hash, Phase::Download, Phase::Extract, Phase::LocalValidation, Phase::CandidateValidation], true)) {
            $unit = in_array($snapshot->phase, [Phase::LocalValidation, Phase::CandidateValidation], true) ? 'ranges' : 'bytes';
            if ($compact) {
                $parameters = ['unit' => $this->messages->get('progress.short_units.'.$unit), 'count' => $snapshot->completed];
                $amount = $snapshot->total !== null && $snapshot->total > 0
                    ? $this->messages->get('progress.compact_total', array_merge($parameters, [
                        'total' => $snapshot->total,
                        'percent' => (string) round(100 * $snapshot->completed / $snapshot->total),
                    ]))
                    : $this->messages->get('progress.compact_count', $parameters);
            } else {
                $amount = $this->messages->choice('progress.'.$unit, $snapshot->completed);
            }
            if (! $compact && $snapshot->total !== null && $snapshot->total > 0) {
                $amount .= ' '.$this->messages->get('progress.total', [
                    'count' => $snapshot->total,
                    'percent' => (string) round(100 * $snapshot->completed / $snapshot->total),
                ]);
            }
            $parts[] = $amount;
        }
        $parts[] = $compact
            ? $this->messages->get('progress.compact_elapsed', ['elapsed' => (string) max(0, (int) round($snapshot->elapsed))])
            : self::duration($this->messages, 'elapsed', $snapshot->elapsed);
        if ($snapshot->total !== null && $snapshot->total > 0) {
            $parts[] = $this->eta($snapshot->eta, $compact);
        }
        if ($snapshot->waitSeconds !== null) {
            $parts[] = $compact
                ? $this->messages->get('progress.compact_wait', ['seconds' => (string) max(0, $snapshot->waitSeconds)])
                : self::duration($this->messages, 'wait', $snapshot->waitSeconds);
        }

        return implode(' | ', $parts);
    }

    private function eta(?float $eta, bool $compact): string
    {
        if ($eta === null || ! is_finite($eta) || $eta < 0) {
            return $this->messages->get($compact ? 'progress.compact_unknown_eta' : 'progress.unknown_eta');
        }
        if ($eta < 60) {
            return $this->messages->get($compact ? 'progress.compact_eta' : 'progress.eta', ['remaining' => (string) max(0, (int) round($eta))]);
        }

        return $this->messages->get($compact ? 'progress.compact_eta_minutes' : 'progress.eta_minutes', ['minutes' => (string) max(1, (int) round($eta / 60))]);
    }

    /** @param array<string, string> $parameters */
    private static function duration(Messages $messages, string $key, float|int $duration, array $parameters = []): string
    {
        $seconds = max(0, (int) round($duration));
        if ($seconds < 60) {
            $placeholder = $key === 'wait' ? 'seconds' : 'elapsed';

            return $messages->get('progress.'.$key, array_merge($parameters, [$placeholder => (string) $seconds]));
        }

        return $messages->get('progress.'.$key.'_minutes', array_merge($parameters, [
            'minutes' => (string) intdiv($seconds, 60),
            'seconds' => (string) ($seconds % 60),
        ]));
    }

    private function bounded(string $row): string
    {
        return mb_strwidth($row) <= $this->width ? $row : mb_strimwidth($row, 0, $this->width, '…');
    }

    private static function supportsInteractiveOutput(OutputInterface $output): bool
    {
        $ci = getenv('CI');

        return $output->isDecorated()
            && $output instanceof StreamOutput
            && ($ci === false || $ci === '' || $ci === '0' || strtolower($ci) === 'false')
            && function_exists('stream_isatty')
            && @stream_isatty($output->getStream());
    }
}
