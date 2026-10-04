<?php

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Symfony\Component\Console\Output\BufferedOutput;
use Trianity\IpAnalyzer\Console\UpdateProgress;
use Trianity\IpAnalyzer\Update\CandidateValidator;
use Trianity\IpAnalyzer\Update\GuzzleTransport;
use Trianity\IpAnalyzer\Update\Progress\Interrupted;
use Trianity\IpAnalyzer\Update\Progress\MonotonicClock;
use Trianity\IpAnalyzer\Update\Progress\Observer;
use Trianity\IpAnalyzer\Update\Progress\Phase;
use Trianity\IpAnalyzer\Update\Progress\Progress;
use Trianity\IpAnalyzer\Update\Progress\Snapshot;
use Trianity\IpAnalyzer\Update\UpdateOptions;

function progressHarness(): array
{
    $clock = new class extends MonotonicClock
    {
        public float $time = 0;

        public function now(): float
        {
            return $this->time;
        }
    };
    $observer = new class implements Observer
    {
        public array $events = [];

        public function report(Snapshot $snapshot): void
        {
            $this->events[] = $snapshot;
        }
    };
    $progress = new Progress($clock);
    $progress->observe($observer);

    return [$progress, $clock, $observer];
}

it('reports real counts without a total when none is known and throttles samples', function () {
    [$p, $clock, $o] = progressHarness();
    $p->start(Phase::LocalValidation, 'country');
    for ($i = 1; $i <= 100; $i++) {
        $clock->time = $i / 100;
        $p->advance($i);
    }
    expect(count($o->events))->toBeLessThanOrEqual(5);
    $last = end($o->events);
    expect($last->completed)->toBe(100)->and($last->total)->toBeNull()->and($last->eta)->toBeNull()->and($last->elapsed)->toBe(1.0);
});

it('estimates a phase only after enough time and samples and handles stalls', function () {
    [$p, $clock, $o] = progressHarness();
    $p->start(Phase::Hash, 'asn', total: 1000);
    $clock->time = .5;
    $p->advance(100);
    expect(end($o->events)->eta)->toBeNull();
    $clock->time = 1;
    $p->advance(200);
    expect(end($o->events)->eta)->toBe(4.0);
    $clock->time = 1.5;
    $p->advance(400);
    expect(end($o->events)->eta)->toBeGreaterThan(0)->toBeLessThan(4);
    $clock->time = 4;
    $p->advance(400);
    expect(end($o->events)->eta)->toBeNull();
    $clock->time = 5;
    $p->advance(1000);
    expect(end($o->events)->eta)->toBe(0.0);
});

it('reports processed CIDR ranges without claiming a trie-derived total or a second record pass', function () {
    [$p, $clock, $o] = progressHarness();
    app()->instance(Progress::class, $p);
    $path = __DIR__.'/../../Fixtures/country.mmdb';
    $result = app(CandidateValidator::class)->validate('country', $path, app(UpdateOptions::class), false);
    expect($o->events[0]->phase)->toBe(Phase::LocalValidation)->and($o->events[0]->completed)->toBe(0);
    $ranges = array_values(array_filter($o->events, fn ($s) => $s->phase === Phase::LocalValidation));
    expect(end($ranges)->completed)->toBeGreaterThan(0)->and(end($ranges)->total)->toBeNull()
        ->and(end($ranges)->eta)->toBeNull();
    $last = end($o->events);
    expect($last->phase)->toBe(Phase::Hash)->and($last->completed)->toBe(filesize($path))->and($last->total)->toBe(filesize($path))
        ->and($result->sha256)->toBe(hash_file('sha256', $path));
});

it('streams measured download bytes and only uses validated content length as total', function ($known) {
    [$p, $clock, $o] = progressHarness();
    $p->start(Phase::Download, 'country');
    config(['ip-analyzer.update.account_id' => '1234', 'ip-analyzer.update.license_key' => 'secret']);
    $handler = new MockHandler([new Response(200, $known ? ['Content-Length' => '6'] : [], 'abcdef')]);
    $transport = new GuzzleTransport(HandlerStack::create($handler), $p);
    $path = tempnam(sys_get_temp_dir(), 'progress-download-');
    try {
        $transport->request('GET', app(UpdateOptions::class)->url('country'), app(UpdateOptions::class), $path);
        $last = end($o->events);
        expect($last->completed)->toBe(6)->and($last->total)->toBe($known ? 6 : null);
    } finally {
        unlink($path);
    }
})->with([true, false]);

it('cancels validation before opening the database and stops record traversal', function () {
    [$p, $clock, $o] = progressHarness();
    $p->observe(new class($p) implements Observer
    {
        public function __construct(private Progress $progress) {}

        public function report(Snapshot $s): void
        {
            if ($s->phase === Phase::LocalValidation) {
                $this->progress->cancel();
            }
        }
    });
    app()->instance(Progress::class, $p);
    expect(fn () => app(CandidateValidator::class)->validate('country', __DIR__.'/../../Fixtures/country.mmdb', app(UpdateOptions::class), false))
        ->toThrow(Interrupted::class);
});

it('renders final counts even for a short non-TTY phase', function () {
    app()->setLocale('hu');
    $output = new BufferedOutput;
    $reporter = new UpdateProgress($output);
    [$p, $clock, $o] = progressHarness();
    $p->observe($reporter);
    $p->start(Phase::LocalValidation, 'country');
    $p->advance(42, force: true);
    expect($output->fetch())->toContain('42 CIDR-tartomány', 'Eltelt: 0 s')->not->toContain('%', 'Hátralévő');
});

it('formats every human duration over sixty seconds as minutes and seconds', function () {
    app()->setLocale('hu');
    $output = new BufferedOutput;
    $reporter = new UpdateProgress($output);
    $reporter->report(new Snapshot(Phase::Download, null, 50, 100, 1675.4, 125.2, 120));
    $reporter->finish(0, 2396.1);

    expect($output->fetch())->toContain(
        'Eltelt: 27 perc 55 s',
        'Hátralévő: ~2 perc',
        'Várakozás/timeout: 2 perc 0 s',
        'Teljes futási idő: 39 perc 56 s',
    );
});

it('formats sixty seconds as whole-minute human progress', function () {
    app()->setLocale('en');
    $output = new BufferedOutput;
    $reporter = new UpdateProgress($output);
    $reporter->report(new Snapshot(Phase::Hash, null, 1, 2, 60.0, 60.1));

    expect($output->fetch())->toContain('Elapsed: 1 min 0 s', 'Remaining: ~1 min');
});

it('does not sample the clock or allocate events for disabled progress', function () {
    $clock = new class extends MonotonicClock
    {
        public function now(): float
        {
            throw new LogicException('No sampling allowed');
        }
    };
    $p = new Progress($clock);
    $p->start(Phase::LocalValidation, 'country');
    for ($i = 0; $i < 1000; $i++) {
        $p->advance($i);
    }
    $p->cancel();
    expect(fn () => $p->checkpoint())->toThrow(Interrupted::class);
});

it('does not estimate a zero total or a zero-speed known-size phase', function () {
    [$p, $clock, $o] = progressHarness();
    $p->start(Phase::Download, 'asn', total: 100);
    $clock->time = 1;
    $p->advance(0);
    $clock->time = 2;
    $p->advance(0);
    expect(end($o->events)->eta)->toBeNull();
    $p->start(Phase::Hash, 'asn', total: 0);
    $clock->time = 3;
    $p->advance(0, force: true);
    expect(end($o->events)->eta)->toBeNull();
});
