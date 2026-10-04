<?php

use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\StreamOutput;
use Trianity\IpAnalyzer\Console\UpdateProgress;
use Trianity\IpAnalyzer\Update\Progress\Database;
use Trianity\IpAnalyzer\Update\Progress\MonotonicClock;
use Trianity\IpAnalyzer\Update\Progress\Phase;
use Trianity\IpAnalyzer\Update\Progress\Snapshot;

function compactProgressHarness(bool $interactive = false, int $width = 120): array
{
    $clock = new class extends MonotonicClock
    {
        public float $time = 0;

        public function now(): float
        {
            return $this->time;
        }
    };
    $output = new BufferedOutput(decorated: $interactive);
    $progress = new UpdateProgress($output, clock: $clock, interactive: $interactive, width: $width);

    return [$progress, $clock, $output];
}

it('collects rapid interactive events and refreshes the compact view at most once per second', function () {
    app()->setLocale('en');
    [$progress, $clock, $output] = compactProgressHarness(true);
    $initial = $output->fetch();

    $progress->report(new Snapshot(Phase::LocalValidation, Database::Country, 1, 100, .1, null));
    $progress->report(new Snapshot(Phase::LocalValidation, Database::Asn, 2, 100, .1, null));
    $output->fetch();
    $progress->report(new Snapshot(Phase::LocalValidation, Database::Country, 20, 100, .5, 3));
    $progress->report(new Snapshot(Phase::LocalValidation, Database::Asn, 25, 100, .5, 3));
    expect($output->fetch())->toBe('');

    $clock->time = 1;
    $progress->report(new Snapshot(Phase::LocalValidation, Database::Asn, 30, 100, 1, 2));
    $rendered = $output->fetch();
    expect($initial)->toContain('Country', 'ASN')
        ->and($rendered)->toContain('Country', 'CIDR 20/100 (20%)', 'ASN', 'CIDR 30/100 (30%)')
        ->and(strpos($rendered, 'Country'))->toBeLessThan(strpos($rendered, 'ASN'));
});

it('renders phase changes and terminal states immediately and retains completed rows', function () {
    app()->setLocale('en');
    [$progress, $clock, $output] = compactProgressHarness(true);
    $output->fetch();

    $progress->report(new Snapshot(Phase::ValidationRunning, Database::Asn, 0, null, 0, null));
    expect($output->fetch())->toContain('Worker running');
    $progress->report(new Snapshot(Phase::ValidationComplete, Database::Country, 0, null, 2, null, finished: true));
    $output->fetch();
    $clock->time = 3;
    $progress->report(new Snapshot(Phase::Hash, Database::Asn, 5, 10, 3, 2));
    expect($output->fetch())->toContain('Country', 'Validation complete', 'ASN', 'SHA-256');
});

it('does not redraw unchanged interactive content and bounds narrow rows', function () {
    app()->setLocale('en');
    [$progress, $clock, $output] = compactProgressHarness(true, 28);
    $output->fetch();
    $progress->report(new Snapshot(Phase::LocalValidation, Database::Country, 1, null, 0, null));
    $first = $output->fetch();
    $clock->time = 2;
    $progress->report(new Snapshot(Phase::LocalValidation, Database::Country, 1, null, 0, null));
    expect($first)->not->toBe('')->and($output->fetch())->toBe('');
    foreach (preg_split('/\R/', preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $first)) as $line) {
        expect(mb_strwidth($line))->toBeLessThanOrEqual(28);
    }
});

it('throttles plain output per database for fifteen seconds without ANSI sequences', function () {
    app()->setLocale('en');
    [$progress, $clock, $output] = compactProgressHarness();
    $output->fetch();
    $progress->report(new Snapshot(Phase::LocalValidation, Database::Country, 1, 100, 1, null));
    $output->fetch();
    $clock->time = 10;
    $progress->report(new Snapshot(Phase::LocalValidation, Database::Country, 10, 100, 10, 5));
    $progress->report(new Snapshot(Phase::LocalValidation, Database::Asn, 7, 100, 10, 5));
    $text = $output->fetch();
    expect($text)->toContain('ASN')->not->toContain('Country', "\033", "\r");
    $clock->time = 16;
    $progress->report(new Snapshot(Phase::LocalValidation, Database::Country, 16, 100, 16, 4));
    expect($output->fetch())->toContain('Country');
});

it('omits percentage and ETA for unknown totals and formats known time at whole-second precision', function () {
    app()->setLocale('en');
    [$progress, $clock, $output] = compactProgressHarness();
    $output->fetch();
    $progress->report(new Snapshot(Phase::LocalValidation, Database::Country, 42, null, 12.8, 99));
    $unknown = $output->fetch();
    expect($unknown)->toContain('42 CIDR ranges', 'Elapsed: 13 s')->not->toContain('%', 'Remaining');
    $progress->report(new Snapshot(Phase::Hash, Database::Country, 50, 100, 65.2, 125.2));
    expect($output->fetch())->toContain('50 bytes / 100 (50%)', 'Elapsed: 1 min 5 s', 'Remaining: ~2 min')->not->toContain('.2');
});

it('keeps the final compact state visible on failure and cancellation', function (int $exit, string $status) {
    app()->setLocale('en');
    [$progress, $clock, $output] = compactProgressHarness(true);
    $output->fetch();
    $progress->report(new Snapshot(Phase::Hash, Database::Country, 1, 10, 1, null));
    $output->fetch();
    $progress->finish($exit, 4.8);
    expect($output->fetch())->toContain($status, 'Total duration: 5 s')->toEndWith(PHP_EOL);
})->with([[1, 'Failed'], [130, 'Interrupted']]);

it('uses plain output for a decorated redirected stream and for no ANSI output', function (bool $decorated) {
    app()->setLocale('en');
    $stream = fopen('php://memory', 'w+');
    $output = new StreamOutput($stream, decorated: $decorated);
    $progress = new UpdateProgress($output);
    $progress->report(new Snapshot(Phase::Hash, Database::Country, 5, 10, 1, null));
    rewind($stream);
    $text = stream_get_contents($stream);
    fclose($stream);

    expect($text)->toContain('Country', 'ASN', 'Computing SHA-256')->not->toContain("\033", "\r");
})->with([true, false]);

it('renders only the selected database in the initial stable view', function () {
    app()->setLocale('en');
    $output = new BufferedOutput;
    new UpdateProgress($output, databases: ['asn']);

    expect($output->fetch())->toContain('ASN')->not->toContain('Country');
});
