<?php

use Illuminate\Support\Facades\Artisan;
use Trianity\IpAnalyzer\Update\DatabaseUpdater;
use Trianity\IpAnalyzer\Update\Parallel\ParallelValidationExecutorContract;
use Trianity\IpAnalyzer\Update\Parallel\ValidationExecutor;
use Trianity\IpAnalyzer\Update\Parallel\ValidationOutcome;
use Trianity\IpAnalyzer\Update\RemoteResponse;
use Trianity\IpAnalyzer\Update\Transport;
use Trianity\IpAnalyzer\Update\UpdateOptions;
use Trianity\IpAnalyzer\Update\UpdateStorage;
use Trianity\IpAnalyzer\Update\ValidatedDatabase;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/ip-workflow-23-'.bin2hex(random_bytes(8));
    mkdir($this->directory, 0700);
    config([
        'ip-analyzer.databases.country' => $this->directory.'/country.mmdb',
        'ip-analyzer.databases.asn' => $this->directory.'/asn.mmdb',
        'ip-analyzer.update.account_id' => '12345',
        'ip-analyzer.update.license_key' => 'synthetic-license',
        'ip-analyzer.update.max_records' => 1,
    ]);
});

function workflow23Transport(string $validator, string $countryFixture = 'country'): Transport
{
    return new class($validator, $countryFixture) implements Transport
    {
        public array $methods = [];

        public function __construct(
            private readonly string $validator,
            private readonly string $countryFixture,
        ) {}

        public function request(string $method, string $url, UpdateOptions $options, ?string $sink = null): RemoteResponse
        {
            $this->methods[] = $method;
            if ($method === 'GET') {
                $edition = str_contains($url, 'GeoLite2-ASN') ? 'GeoLite2-ASN' : 'GeoLite2-Country';
                $fixture = $edition === 'GeoLite2-ASN' ? 'asn' : $this->countryFixture;
                file_put_contents($sink, syntheticArchive([
                    syntheticTarEntry($edition.'.mmdb', file_get_contents(__DIR__.'/../../Fixtures/'.$fixture.'.mmdb')),
                ]));
            }

            return new RemoteResponse(200, $this->validator);
        }
    };
}

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

it('reports unknown freshness without state and never deeply validates during check', function () {
    copy(__DIR__.'/../../Fixtures/country.mmdb', $this->directory.'/country.mmdb');
    $validations = Mockery::mock(ValidationExecutor::class);
    $validations->shouldNotReceive('execute');
    app()->instance(ValidationExecutor::class, $validations);
    $transport = new class implements Transport
    {
        public array $methods = [];

        public function request(string $method, string $url, UpdateOptions $options, ?string $sink = null): RemoteResponse
        {
            $this->methods[] = $method;

            return new RemoteResponse(200, 'remote-release');
        }
    };
    app()->instance(Transport::class, $transport);

    $result = app(DatabaseUpdater::class)->run(['country'], check: true)[0];

    expect($result->status)->toBe('freshness_unknown')
        ->and($transport->methods)->toBe(['HEAD']);
});

it('registers an offline installed database verification command', function () {
    config(['ip-analyzer.update.max_records' => 5000000]);
    copy(__DIR__.'/../../Fixtures/country.mmdb', $this->directory.'/country.mmdb');
    $transport = Mockery::mock(Transport::class);
    $transport->shouldNotReceive('request');
    app()->instance(Transport::class, $transport);

    expect(Artisan::call('ip-data:verify', [
        '--database' => ['country'],
        '--workers' => '1',
        '--json' => true,
    ]))->toBe(0);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($result['results'][0]['status'])->toBe('verified')
        ->and($result['results'][0]['sha256'])->toBe(hash_file('sha256', $this->directory.'/country.mmdb'));
});

it('distinguishes matching and changed validators without claiming integrity', function () {
    config(['ip-analyzer.update.max_records' => 5000000]);
    app()->instance(Transport::class, workflow23Transport('release-a'));
    expect(app(DatabaseUpdater::class)->run(['country'])[0]->status)->toBe('updated');

    config(['ip-analyzer.update.max_records' => 1]);
    app()->instance(Transport::class, workflow23Transport('release-a'));
    $matching = app(DatabaseUpdater::class)->run(['country'], check: true)[0];
    app()->instance(Transport::class, workflow23Transport('release-b'));
    $changed = app(DatabaseUpdater::class)->run(['country'], check: true)[0];

    expect($matching->status)->toBe('up_to_date')
        ->and($matching->integrityVerified)->toBeFalse()
        ->and($changed->status)->toBe('update_available')
        ->and($changed->integrityVerified)->toBeFalse();
});

it('reports invalid local metadata independently from matching remote freshness', function () {
    config(['ip-analyzer.update.max_records' => 5000000]);
    app()->instance(Transport::class, workflow23Transport('release-a'));
    app(DatabaseUpdater::class)->run(['country']);
    copy(__DIR__.'/../../Fixtures/asn.mmdb', $this->directory.'/country.mmdb');

    app()->instance(Transport::class, workflow23Transport('release-a'));
    $result = app(DatabaseUpdater::class)->run(['country'], check: true)[0];

    expect($result->status)->toBe('up_to_date')
        ->and($result->localStatus)->toBe('invalid')
        ->and($result->localErrorCode)->toBe('database_type_mismatch')
        ->and($result->integrityVerified)->toBeFalse();
});

it('validates both prepared candidates in one worker-limited batch before installation', function () {
    config(['ip-analyzer.update.max_records' => 5000000]);
    $executor = new class($this->directory) implements ValidationExecutor
    {
        public array $calls = [];

        public array $installedDuringValidation = [];

        public function __construct(private readonly string $directory) {}

        public function execute(array $tasks, int $workers, int $timeout): array
        {
            $this->calls[] = [$tasks, $workers, $timeout];
            $this->installedDuringValidation = [
                is_file($this->directory.'/country.mmdb'),
                is_file($this->directory.'/asn.mmdb'),
            ];
            $outcomes = [];
            foreach ($tasks as $task) {
                $outcomes[$task->id] = new ValidationOutcome(
                    $task->id,
                    $task->database,
                    new ValidatedDatabase(1700000000, hash_file('sha256', $task->path)),
                );
            }

            return $outcomes;
        }
    };
    app()->instance(ValidationExecutor::class, $executor);
    $transport = workflow23Transport('release-a');
    app()->instance(Transport::class, $transport);

    $results = app(DatabaseUpdater::class)->run(workers: 2);

    expect($executor->calls)->toHaveCount(1)
        ->and($executor->calls[0][1])->toBe(2)
        ->and(array_column($executor->calls[0][0], 'id'))->toBe(['candidate-country', 'candidate-asn'])
        ->and(array_column($executor->calls[0][0], 'requireEdition'))->toBe([true, true])
        ->and($executor->installedDuringValidation)->toBe([false, false])
        ->and($executor->calls[0][0][0]->path)->not->toBe($this->directory.'/country.mmdb')
        ->and($executor->calls[0][0][1]->path)->not->toBe($this->directory.'/asn.mmdb')
        ->and(array_column($results, 'status'))->toBe(['updated', 'updated'])
        ->and(array_column($results, 'integrityVerified'))->toBe([true, true])
        ->and($transport->methods)->toBe(['HEAD', 'GET', 'HEAD', 'GET']);
});

it('keeps installed bytes and state unchanged when candidate validation fails', function () {
    config(['ip-analyzer.update.max_records' => 5000000]);
    app()->instance(Transport::class, workflow23Transport('release-a'));
    app(DatabaseUpdater::class)->run(['country']);
    $target = $this->directory.'/country.mmdb';
    $statePath = app(UpdateStorage::class)->directory($target).'/state.json';
    $beforeHash = hash_file('sha256', $target);
    $beforeState = file_get_contents($statePath);

    app()->instance(Transport::class, workflow23Transport('release-b', 'wrong-type'));
    $result = app(DatabaseUpdater::class)->run(['country'])[0];

    expect($result->status)->toBe('failed')
        ->and($result->installed)->toBeFalse()
        ->and(hash_file('sha256', $target))->toBe($beforeHash)
        ->and(file_get_contents($statePath))->toBe($beforeState);
});

it('dispatches two update candidates through the configured parallel executor', function () {
    config(['ip-analyzer.update.max_records' => 5000000]);
    $parallel = Mockery::mock(ParallelValidationExecutorContract::class);
    $parallel->shouldReceive('supported')->once()->andReturnTrue();
    $parallel->shouldReceive('execute')->once()->with(Mockery::type('array'), 2, 1800)
        ->andReturnUsing(function (array $tasks): array {
            expect(array_column($tasks, 'id'))->toBe(['candidate-country', 'candidate-asn']);
            $outcomes = [];
            foreach ($tasks as $task) {
                $outcomes[$task->id] = new ValidationOutcome(
                    $task->id,
                    $task->database,
                    new ValidatedDatabase(1700000000, hash_file('sha256', $task->path)),
                );
            }

            return $outcomes;
        });
    app()->instance(ParallelValidationExecutorContract::class, $parallel);
    app()->instance(Transport::class, workflow23Transport('release-a'));

    expect(array_column(app(DatabaseUpdater::class)->run(workers: 2), 'status'))
        ->toBe(['updated', 'updated']);
});

it('keeps one update candidate in process even when two workers are requested', function () {
    config(['ip-analyzer.update.max_records' => 5000000]);
    $parallel = Mockery::mock(ParallelValidationExecutorContract::class);
    $parallel->shouldNotReceive('supported', 'execute');
    app()->instance(ParallelValidationExecutorContract::class, $parallel);
    app()->instance(Transport::class, workflow23Transport('release-a'));

    $result = app(DatabaseUpdater::class)->run(['country'], workers: 2)[0];

    expect($result->status)->toBe('updated')
        ->and($result->integrityVerified)->toBeTrue();
});

it('fails verification for a corrupt installed file without network access', function () {
    file_put_contents($this->directory.'/country.mmdb', 'not-an-mmdb');
    $transport = Mockery::mock(Transport::class);
    $transport->shouldNotReceive('request');
    app()->instance(Transport::class, $transport);

    expect(Artisan::call('ip-data:verify', [
        '--database' => ['country'], '--json' => true,
    ]))->toBe(1);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['results'][0];
    expect($result['status'])->toBe('failed')
        ->and($result['integrityVerified'])->toBeFalse();
});
