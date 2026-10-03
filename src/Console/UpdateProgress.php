<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Console;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
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

    public function __construct(private readonly OutputInterface $output)
    {
        $this->tty = $output instanceof StreamOutput && stream_isatty($output->getStream()) && $output->isDecorated();
        $output->writeln('A helyi adatbázis teljes integritásvizsgálata több percig tarthat.', OutputInterface::OUTPUT_RAW);
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
        $name = match ($snapshot->database) {
            Database::Country => 'Country', Database::Asn => 'ASN', null => 'Update'
        };
        $text = $name.' | '.$snapshot->phase->value;
        if (in_array($snapshot->phase, [Phase::Hash, Phase::Download, Phase::Extract, Phase::LocalValidation, Phase::CandidateValidation], true)) {
            $unit = in_array($snapshot->phase, [Phase::LocalValidation, Phase::CandidateValidation], true) ? 'tartomány' : 'bájt';
            $text .= ' | '.$snapshot->completed.' '.$unit;
            if ($snapshot->total !== null && $snapshot->total > 0) {
                $text .= sprintf(' / %d (%.1f%%)', $snapshot->total, min(100, 100 * $snapshot->completed / $snapshot->total));
            }
        }
        $text .= sprintf(' | Eltelt: %.1f s', $snapshot->elapsed);
        $text .= $snapshot->eta === null ? ' | Hátralévő idő: még nem becsülhető' : sprintf(' | Fázis hátralévő ideje: %.1f s', $snapshot->eta);
        if ($snapshot->waitSeconds !== null) {
            $text .= ' | Várakozás/timeout: '.$snapshot->waitSeconds.' s';
        }
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
        $this->output->writeln(self::summary($exit, $elapsed), OutputInterface::OUTPUT_RAW);
    }

    public static function summary(int $exit, float $elapsed): string
    {
        $label = match ($exit) {
            0 => 'Kész', 130 => 'Megszakítva', 3 => 'Foglalt', default => 'Hiba'
        };

        return sprintf('%s | Teljes futási idő: %.1f s', $label, max(0, $elapsed));
    }

    public function endLine(): void
    {
        if ($this->line) {
            $this->output->writeln('');
            $this->line = false;
        }
    }
}
