<?php

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Trianity\IpAnalyzer\Update\Parallel\ParallelUnavailable;
use Trianity\IpAnalyzer\Update\Parallel\ParallelValidationExecutor;
use Trianity\IpAnalyzer\Update\Parallel\ParallelValidationExecutorContract;
use Trianity\IpAnalyzer\Update\Parallel\ProtocolDecoder;
use Trianity\IpAnalyzer\Update\Parallel\SequentialValidationExecutor;
use Trianity\IpAnalyzer\Update\Parallel\ValidationOutcome;
use Trianity\IpAnalyzer\Update\Parallel\ValidationTask;
use Trianity\IpAnalyzer\Update\Parallel\WorkerProcess;
use Trianity\IpAnalyzer\Update\Parallel\WorkerProcessFactory;
use Trianity\IpAnalyzer\Update\Progress\Interrupted;
use Trianity\IpAnalyzer\Update\Progress\Observer;
use Trianity\IpAnalyzer\Update\Progress\Progress;
use Trianity\IpAnalyzer\Update\Progress\Snapshot;
use Trianity\IpAnalyzer\Update\Transport;
use Trianity\IpAnalyzer\Update\UpdateFailure;
use Trianity\IpAnalyzer\Update\UpdateOptions;
use Trianity\IpAnalyzer\Update\UpdateStorage;
use Trianity\IpAnalyzer\Update\ValidatedDatabase;
use Trianity\IpAnalyzer\Update\ValidationOptions;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/ip-parallel-'.bin2hex(random_bytes(8));
    mkdir($this->directory, 0700);
    copy(__DIR__.'/../../Fixtures/country.mmdb', $this->directory.'/country.mmdb');
    copy(__DIR__.'/../../Fixtures/asn.mmdb', $this->directory.'/asn.mmdb');
    config([
        'ip-analyzer.databases.country' => $this->directory.'/country.mmdb',
        'ip-analyzer.databases.asn' => $this->directory.'/asn.mmdb',
        'ip-analyzer.update.account_id' => '1234',
        'ip-analyzer.update.license_key' => 'synthetic-secret',
    ]);
});

afterEach(function () {
    $remove = function (string $path) use (&$remove): void {
        if (is_dir($path) && ! is_link($path)) {
            foreach (new FilesystemIterator($path) as $file) {
                $remove($file->getPathname());
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    };
    $remove($this->directory);
});

it('uses validation defaults config and CLI precedence', function () {
    expect(app(ValidationOptions::class)->workers())->toBe(1)
        ->and(app(ValidationOptions::class)->timeout())->toBe(1800);

    config(['ip-analyzer.validation.workers' => '2', 'ip-analyzer.validation.worker_timeout' => '45']);
    expect(app(ValidationOptions::class)->workers())->toBe(2)
        ->and(app(ValidationOptions::class)->workers('1'))->toBe(1)
        ->and(app(ValidationOptions::class)->timeout())->toBe(45);
});

it('rejects invalid worker configuration and CLI before HTTP', function ($config, $cli) {
    config($config);
    $transport = Mockery::mock(Transport::class);
    $transport->shouldNotReceive('request');
    app()->instance(Transport::class, $transport);

    expect(Artisan::call('ip-data:update', ['--check' => true, '--workers' => $cli, '--json' => true]))->toBe(2);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error'])->toBe('invalid_update_configuration');
})->with([
    [['ip-analyzer.validation.workers' => 0], null],
    [['ip-analyzer.validation.worker_timeout' => 0], null],
    [['ip-analyzer.validation' => 'invalid'], null],
    [[], '0'],
    [[], '1.5'],
]);

it('does not start subprocesses with one worker or one selected database', function ($workers, $databases) {
    config(['ip-analyzer.validation.workers' => $workers]);
    $parallel = Mockery::mock(ParallelValidationExecutorContract::class);
    $parallel->shouldNotReceive('supported', 'execute');
    app()->instance(ParallelValidationExecutorContract::class, $parallel);
    expect(Artisan::call('ip-data:verify', ['--database' => $databases, '--json' => true]))->toBe(0);
})->with([
    [1, ['country', 'asn']],
    [2, ['country']],
]);

it('lets the CLI force sequential execution over parallel configuration', function () {
    config(['ip-analyzer.validation.workers' => 2]);
    $parallel = Mockery::mock(ParallelValidationExecutorContract::class);
    $parallel->shouldNotReceive('supported', 'execute');
    app()->instance(ParallelValidationExecutorContract::class, $parallel);
    expect(Artisan::call('ip-data:verify', [
        '--workers' => '1', '--json' => true,
    ]))->toBe(0);
});

it('runs both validations through real subprocesses and preserves requested result order', function () {
    config(['ip-analyzer.validation.workers' => 2, 'ip-analyzer.validation.worker_timeout' => 30]);
    expect(Artisan::call('ip-data:verify', [
        '--database' => ['asn', 'country'], '--json' => true,
    ]))->toBe(0);
    $results = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['results'];
    expect(array_column($results, 'database'))->toBe(['asn', 'country'])
        ->and(array_column($results, 'buildEpoch'))->toBe([1700000000, 1700000000])
        ->and(array_map('basename', glob($this->directory.'/*') ?: []))->toBe(['asn.mmdb', 'country.mmdb']);
});

it('returns identical validation data in sequential and real parallel execution', function () {
    $tasks = [
        new ValidationTask('country-task', 'country', $this->directory.'/country.mmdb', false),
        new ValidationTask('asn-task', 'asn', $this->directory.'/asn.mmdb', false),
    ];
    $sequential = app(SequentialValidationExecutor::class)->execute($tasks, 1, 30);
    $executor = app(ParallelValidationExecutorContract::class);
    expect($executor->supported())->toBeTrue();
    $parallel = $executor->execute($tasks, 2, 30);

    foreach (array_keys($sequential) as $id) {
        expect($parallel[$id]->result?->buildEpoch)->toBe($sequential[$id]->result?->buildEpoch)
            ->and($parallel[$id]->result?->sha256)->toBe($sequential[$id]->result?->sha256);
    }
});

it('decodes partial and combined NDJSON chunks without mixing task identities', function () {
    $task = new ValidationTask('task-country', 'country', '/tmp/country.mmdb', false);
    $decoder = new ProtocolDecoder($task);
    expect($decoder->push('{"type":"progress","task":"task-country","database":"country","phase":"local_val'))->toBe([]);
    $messages = $decoder->push('idation","completed":1,"total":2,"elapsed":1.0,"eta":1.0,"waitSeconds":null,"finished":false}'."\n".
        '{"type":"result","task":"task-country","database":"country","ok":true,"buildEpoch":1700000000,"sha256":"'.str_repeat('a', 64).'"}'."\n");
    expect($messages)->toHaveCount(2)
        ->and($decoder->finish()->result?->buildEpoch)->toBe(1700000000);
});

it('rejects malformed unknown duplicate missing and oversized protocol messages', function ($feed, $finish) {
    $task = new ValidationTask('task-country', 'country', '/tmp/country.mmdb', false);
    $decoder = new ProtocolDecoder($task);
    expect(function () use ($decoder, $feed, $finish): void {
        foreach ($feed as $chunk) {
            $decoder->push($chunk);
        }
        if ($finish) {
            $decoder->finish();
        }
    })->toThrow(UpdateFailure::class, 'validation_worker_protocol');
})->with([
    [['not-json'."\n"], false],
    [['{"type":"result","task":"other","database":"country","ok":false,"errorCode":"invalid_database"}'."\n"], false],
    [['{"type":"progress","task":"task-country","database":"country","phase":"download","completed":1,"total":2,"elapsed":1,"eta":1,"waitSeconds":null,"finished":false}'."\n"], false],
    [[
        '{"type":"result","task":"task-country","database":"country","ok":false,"errorCode":"invalid_database"}'."\n".
        '{"type":"result","task":"task-country","database":"country","ok":false,"errorCode":"invalid_database"}'."\n",
    ], false],
    [[], true],
    [[str_repeat('x', 65537)], false],
]);

it('starts the second worker before the first finishes without timing assertions', function () {
    $trace = new ArrayObject;
    $factory = new class($trace) implements WorkerProcessFactory
    {
        public function __construct(private ArrayObject $trace) {}

        public function supported(): bool
        {
            return true;
        }

        public function create(ValidationTask $task, int $timeout): WorkerProcess
        {
            return new class($task, $this->trace) implements WorkerProcess
            {
                private bool $running = false;

                private bool $finished = false;

                private $output;

                public function __construct(private ValidationTask $task, private ArrayObject $trace) {}

                public function start(callable $output): void
                {
                    $this->output = $output;
                    $this->running = true;
                    $this->trace[] = 'start-'.$this->task->database;
                }

                public function tick(): void
                {
                    if (! $this->finished) {
                        ($this->output)('out', json_encode([
                            'type' => 'result', 'task' => $this->task->id, 'database' => $this->task->database,
                            'ok' => true, 'buildEpoch' => 1700000000, 'sha256' => str_repeat('a', 64),
                        ], JSON_THROW_ON_ERROR)."\n");
                        $this->finished = true;
                        $this->running = false;
                        $this->trace[] = 'finish-'.$this->task->database;
                    }
                }

                public function running(): bool
                {
                    return $this->running;
                }

                public function successful(): bool
                {
                    return $this->finished;
                }

                public function stop(): void
                {
                    $this->running = false;
                    $this->trace[] = 'stop-'.$this->task->database;
                }
            };
        }
    };
    $executor = new ParallelValidationExecutor($factory, new Progress);
    $outcomes = $executor->execute([
        new ValidationTask('one', 'country', '/tmp/country', false),
        new ValidationTask('two', 'asn', '/tmp/asn', false),
    ], 2, 30);

    expect(array_slice($trace->getArrayCopy(), 0, 2))->toBe(['start-country', 'start-asn'])
        ->and(array_keys($outcomes))->toBe(['one', 'two']);
});

it('stops running peers after malformed worker output', function () {
    $trace = new ArrayObject;
    $factory = new class($trace) implements WorkerProcessFactory
    {
        public function __construct(private ArrayObject $trace) {}

        public function supported(): bool
        {
            return true;
        }

        public function create(ValidationTask $task, int $timeout): WorkerProcess
        {
            return new class($task, $this->trace) implements WorkerProcess
            {
                private bool $running = false;

                private $output;

                public function __construct(private ValidationTask $task, private ArrayObject $trace) {}

                public function start(callable $output): void
                {
                    $this->output = $output;
                    $this->running = true;
                    $this->trace[] = 'start-'.$this->task->id;
                }

                public function tick(): void
                {
                    if ($this->task->database === 'country') {
                        ($this->output)('out', "invalid\n");
                    }
                }

                public function running(): bool
                {
                    return $this->running;
                }

                public function successful(): bool
                {
                    return false;
                }

                public function stop(): void
                {
                    $this->running = false;
                    $this->trace[] = 'stop-'.$this->task->database;
                }
            };
        }
    };
    $executor = new ParallelValidationExecutor($factory, new Progress);
    expect(fn () => $executor->execute([
        new ValidationTask('one', 'country', '/tmp/country', false),
        new ValidationTask('two', 'asn', '/tmp/asn', false),
        new ValidationTask('three', 'country', '/tmp/later-country', false),
    ], 2, 30))->toThrow(UpdateFailure::class, 'validation_worker_protocol');
    expect($trace->getArrayCopy())->toContain('stop-country', 'stop-asn')
        ->not->toContain('start-three');
});

it('stops a started worker when the main progress is cancelled', function () {
    $trace = new ArrayObject;
    $factory = new class($trace) implements WorkerProcessFactory
    {
        public function __construct(private ArrayObject $trace) {}

        public function supported(): bool
        {
            return true;
        }

        public function create(ValidationTask $task, int $timeout): WorkerProcess
        {
            return new class($task, $this->trace) implements WorkerProcess
            {
                public function __construct(private ValidationTask $task, private ArrayObject $trace) {}

                public function start(callable $output): void {}

                public function tick(): void {}

                public function running(): bool
                {
                    return true;
                }

                public function successful(): bool
                {
                    return false;
                }

                public function stop(): void
                {
                    $this->trace[] = 'stop-'.$this->task->database;
                }
            };
        }
    };
    $progress = new Progress;
    $progress->observe(new class($progress) implements Observer
    {
        public function __construct(private Progress $progress) {}

        public function report(Snapshot $snapshot): void
        {
            $this->progress->cancel();
        }
    });
    $executor = new ParallelValidationExecutor($factory, $progress);

    expect(fn () => $executor->execute([
        new ValidationTask('one', 'country', '/tmp/country', false),
        new ValidationTask('two', 'asn', '/tmp/asn', false),
    ], 2, 30))->toThrow(Interrupted::class);
    expect($trace->getArrayCopy())->toBe(['stop-country']);
});

it('falls back before starting work and keeps JSON stdout clean', function () {
    app()->setLocale('hu');
    config(['ip-analyzer.validation.workers' => 2]);
    $parallel = Mockery::mock(ParallelValidationExecutorContract::class);
    $parallel->shouldReceive('supported')->once()->andReturnFalse();
    $parallel->shouldNotReceive('execute');
    app()->instance(ParallelValidationExecutorContract::class, $parallel);
    [$output, $out, $err] = separatedProgressOutput();

    expect(Artisan::call('ip-data:verify', [
        '--json' => true, '--progress' => true,
    ], $output))->toBe(0);
    expect(json_decode(progressText($out), true, flags: JSON_THROW_ON_ERROR)['results'])->toHaveCount(2);
    expect(progressText($err))->toContain('szekvenciális validálás')->not->toContain("\033", "\r");
    fclose($out);
    fclose($err);
});

it('falls back when the first worker cannot be started', function () {
    config(['ip-analyzer.validation.workers' => 2]);
    $parallel = Mockery::mock(ParallelValidationExecutorContract::class);
    $parallel->shouldReceive('supported')->once()->andReturnTrue();
    $parallel->shouldReceive('execute')->once()->andThrow(new ParallelUnavailable);
    app()->instance(ParallelValidationExecutorContract::class, $parallel);
    expect(Artisan::call('ip-data:verify', ['--json' => true]))->toBe(0);
});

it('maps missing results crashes and timeouts to bounded worker failures', function ($mode, $expected) {
    $factory = new class($mode) implements WorkerProcessFactory
    {
        public function __construct(private string $mode) {}

        public function supported(): bool
        {
            return true;
        }

        public function create(ValidationTask $task, int $timeout): WorkerProcess
        {
            return new class($this->mode) implements WorkerProcess
            {
                private bool $running = false;

                public function __construct(private string $mode) {}

                public function start(callable $output): void
                {
                    $this->running = true;
                }

                public function tick(): void
                {
                    if ($this->mode === 'timeout') {
                        $process = new SymfonyProcess(['php', '-v']);
                        $process->setTimeout(1);
                        throw new ProcessTimedOutException($process, ProcessTimedOutException::TYPE_GENERAL);
                    }
                    $this->running = false;
                }

                public function running(): bool
                {
                    return $this->running;
                }

                public function successful(): bool
                {
                    return $this->mode === 'missing';
                }

                public function stop(): void
                {
                    $this->running = false;
                }
            };
        }
    };
    $executor = new ParallelValidationExecutor($factory, new Progress);
    expect(fn () => $executor->execute([
        new ValidationTask('one', 'country', '/tmp/country', false),
        new ValidationTask('two', 'asn', '/tmp/asn', false),
    ], 2, 30))->toThrow(UpdateFailure::class, $expected);
})->with([
    ['missing', 'validation_worker_protocol'],
    ['crash', 'validation_worker_failed'],
    ['timeout', 'validation_worker_timeout'],
]);

it('rejects a replaced validated file and releases both main-process locks', function () {
    config(['ip-analyzer.validation.workers' => 9]);
    $storage = app(UpdateStorage::class);
    foreach (['country', 'asn'] as $database) {
        $lock = $storage->lock($this->directory.'/'.$database.'.mmdb', false, app(UpdateOptions::class));
        $storage->unlock($lock);
    }
    $parallel = Mockery::mock(ParallelValidationExecutorContract::class);
    $parallel->shouldReceive('supported')->once()->andReturnTrue();
    $parallel->shouldReceive('execute')->once()->with(Mockery::type('array'), 2, 1800)
        ->andReturnUsing(function (array $tasks): array {
            $replacement = $this->directory.'/replacement.mmdb';
            copy(__DIR__.'/../../Fixtures/country-next.mmdb', $replacement);
            rename($replacement, $this->directory.'/country.mmdb');

            return [
                $tasks[0]->id => new ValidationOutcome(
                    $tasks[0]->id,
                    $tasks[0]->database,
                    new ValidatedDatabase(1700000000, str_repeat('a', 64)),
                ),
                $tasks[1]->id => new ValidationOutcome(
                    $tasks[1]->id,
                    $tasks[1]->database,
                    new ValidatedDatabase(1700000000, str_repeat('b', 64)),
                ),
            ];
        });
    app()->instance(ParallelValidationExecutorContract::class, $parallel);
    expect(Artisan::call('ip-data:verify', ['--json' => true]))->toBe(1);
    $results = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['results'];
    expect($results[0]['errorCode'])->toBe('validation_file_changed')
        ->and($results[1]['status'])->toBe('verified');
    foreach (['country', 'asn'] as $database) {
        $lock = $storage->lock($this->directory.'/'.$database.'.mmdb', true, app(UpdateOptions::class));
        $storage->unlock($lock);
    }
});

it('localizes parallel progress per database while keeping JSON on stdout', function () {
    app()->setLocale('hu');
    config(['ip-analyzer.validation.workers' => 2]);
    [$output, $out, $err] = separatedProgressOutput();

    expect(Artisan::call('ip-data:verify', [
        '--json' => true, '--progress' => true,
    ], $output))->toBe(0);
    expect(json_decode(progressText($out), true, flags: JSON_THROW_ON_ERROR)['results'])->toHaveCount(2);
    expect(progressText($err))->toContain('Ország', 'ASN', 'Helyi MMDB', 'SHA-256');
    fclose($out);
    fclose($err);
});

it('keeps worker protocol out of quiet and no-progress output', function (bool $quiet) {
    config(['ip-analyzer.validation.workers' => 2]);
    [$output, $out, $err] = separatedProgressOutput();
    $arguments = [$quiet ? '--quiet' : '--no-progress' => true];
    if ($quiet) {
        $arguments['--json'] = true;
    }

    expect(Artisan::call('ip-data:verify', $arguments, $output))->toBe(0);
    if ($quiet) {
        expect(progressText($out))->toBe('');
    } else {
        expect(progressText($out))->toContain('Total duration')->not->toContain(
            'validation worker',
            '"type":"progress"',
            '"type":"result"',
        );
    }
    expect(progressText($err))->toBe('');
    fclose($out);
    fclose($err);
})->with([true, false]);

it('renders invalid validation settings as a localized configuration error', function () {
    app()->setLocale('hu');
    config(['ip-analyzer.validation.workers' => 0]);
    $transport = Mockery::mock(Transport::class);
    $transport->shouldNotReceive('request');
    app()->instance(Transport::class, $transport);

    expect(Artisan::call('ip-data:update', ['--check' => true]))->toBe(2);
    expect(Artisan::output())->toContain('Hibás validálási beállítás: workers.');
});
