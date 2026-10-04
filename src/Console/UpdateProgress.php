<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Console;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Trianity\IpAnalyzer\Support\Messages;
use Trianity\IpAnalyzer\Update\Progress\Database;
use Trianity\IpAnalyzer\Update\Progress\Observer;
use Trianity\IpAnalyzer\Update\Progress\Phase;
use Trianity\IpAnalyzer\Update\Progress\Snapshot;

final class UpdateProgress implements Observer
{
    private bool $line = false;

    private bool $tty;

    private ?Phase $phase = null;

    private ?Database $database = null;

    private float $last = 0;

    private readonly Messages $messages;

    public function __construct(private readonly OutputInterface $output, ?Messages $messages = null)
    {
        $this->messages = $messages ?? app(Messages::class);
        $this->tty = $output instanceof StreamOutput && stream_isatty($output->getStream()) && $output->isDecorated();
        $output->writeln($this->messages->get('progress.start'), OutputInterface::OUTPUT_RAW);
    }

    public function report(Snapshot $snapshot): void
    {
        $changed = $this->phase !== $snapshot->phase || $this->database !== $snapshot->database;
        if (! $changed && ! $snapshot->finished && ! $this->tty && $snapshot->elapsed - $this->last < 5
            && ! ($snapshot->total !== null && $snapshot->completed >= $snapshot->total)) {
            return;
        }
        if ($changed) {
            $this->endLine();
        }
        $this->phase = $snapshot->phase;
        $this->database = $snapshot->database;
        $this->last = $snapshot->elapsed;
        $name = $this->messages->get('databases.'.($snapshot->database->value ?? 'update'));
        $parts = [$this->messages->get('progress.phase', [
            'database' => $name, 'phase' => $this->messages->get('phases.'.$snapshot->phase->value),
        ])];
        if (in_array($snapshot->phase, [Phase::Hash, Phase::Download, Phase::Extract, Phase::LocalValidation, Phase::CandidateValidation], true)) {
            $unit = in_array($snapshot->phase, [Phase::LocalValidation, Phase::CandidateValidation], true) ? 'ranges' : 'bytes';
            $amount = $this->messages->choice('progress.'.$unit, $snapshot->completed);
            if ($snapshot->total !== null && $snapshot->total > 0) {
                $amount .= ' '.$this->messages->get('progress.total', [
                    'count' => $snapshot->total, 'percent' => sprintf('%.1f', min(100, 100 * $snapshot->completed / $snapshot->total)),
                ]);
            }
            $parts[] = $amount;
        }
        $parts[] = self::time($this->messages, 'elapsed', $snapshot->elapsed, 'elapsed');
        $parts[] = $snapshot->eta === null ? $this->messages->get('progress.unknown_eta')
            : self::time($this->messages, 'eta', $snapshot->eta, 'remaining');
        if ($snapshot->waitSeconds !== null) {
            $parts[] = self::time($this->messages, 'wait', $snapshot->waitSeconds, 'seconds', false);
        }
        $text = implode(' | ', $parts);
        if ($this->tty) {
            $this->output->write("\r\033[2K".$text, false, OutputInterface::OUTPUT_RAW);
            $this->line = true;
        } else {
            $this->output->writeln($text, OutputInterface::OUTPUT_RAW);
        }
    }

    public function finish(int $exit, float $elapsed): void
    {
        $this->endLine();
        $this->output->writeln(self::summary($exit, $elapsed, $this->messages), OutputInterface::OUTPUT_RAW);
    }

    public static function summary(int $exit, float $elapsed, ?Messages $messages = null): string
    {
        $label = match ($exit) {
            0 => 'done', 130 => 'interrupted', 3 => 'busy', default => 'failed'
        };

        $messages ??= app(Messages::class);

        return self::time($messages, 'summary', $elapsed, 'elapsed', parameters: ['status' => $messages->get('phases.'.$label)]);
    }

    /** @param array<string, string> $parameters */
    private static function time(
        Messages $messages,
        string $key,
        float $duration,
        string $shortPlaceholder,
        bool $decimal = true,
        array $parameters = [],
    ): string {
        $long = $duration > 60;
        $duration = max(0, $decimal ? round($duration, 1) : round($duration));
        if (! $long) {
            $value = $decimal ? sprintf('%.1f', $duration) : (string) (int) $duration;

            return $messages->get('progress.'.$key, array_merge($parameters, [$shortPlaceholder => $value]));
        }

        $minutes = (int) floor($duration / 60);
        $seconds = $duration - $minutes * 60;

        return $messages->get('progress.'.$key.'_minutes', array_merge($parameters, [
            'minutes' => (string) $minutes,
            'seconds' => $decimal ? sprintf('%.1f', $seconds) : (string) (int) $seconds,
        ]));
    }

    public function endLine(): void
    {
        if ($this->line) {
            $this->output->writeln('');
            $this->line = false;
        }
    }
}
