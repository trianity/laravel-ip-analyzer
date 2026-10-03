<?php

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Artisan;
use Trianity\IpAnalyzer\Contracts\IpLookup;
use Trianity\IpAnalyzer\Update\DatabaseUpdater;
use Trianity\IpAnalyzer\Update\GuzzleTransport;
use Trianity\IpAnalyzer\Update\RemoteResponse;
use Trianity\IpAnalyzer\Update\Sleeper;
use Trianity\IpAnalyzer\Update\Transport;
use Trianity\IpAnalyzer\Update\UpdateFailure;
use Trianity\IpAnalyzer\Update\UpdateOptions;
use Trianity\IpAnalyzer\Update\UpdateStorage;

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/ip-updater-'.bin2hex(random_bytes(8));
    mkdir($this->directory, 0700);
    config(['ip-analyzer.databases.country' => $this->directory.'/country.mmdb',
        'ip-analyzer.databases.asn' => $this->directory.'/asn.mmdb',
        'ip-analyzer.update.account_id' => '12345', 'ip-analyzer.update.license_key' => 'synthetic-license']);
});
afterEach(function () {
    $remove = function ($path) use (&$remove) {
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

function updateTransport(string $fixture = 'country', string $validator = 'release-a', int $status = 200): Transport
{
    return new class($fixture, $validator, $status) implements Transport
    {
        public array $methods = [];

        public function __construct(private string $fixture, private string $validator, private int $status) {}

        public function request(string $method, string $url, UpdateOptions $options, ?string $sink = null): RemoteResponse
        {
            $this->methods[] = $method;
            if ($method === 'GET' && $this->status === 200) {
                $edition = str_contains($url, 'GeoLite2-ASN') ? 'GeoLite2-ASN' : 'GeoLite2-Country';
                $fixture = $edition === 'GeoLite2-ASN' ? 'asn' : $this->fixture;
                file_put_contents($sink, syntheticArchive([
                    syntheticTarEntry('release/'.$edition.'.mmdb', file_get_contents(__DIR__.'/../../Fixtures/'.$fixture.'.mmdb')),
                    syntheticTarEntry('release/LICENSE.txt', 'Synthetic MIT'),
                ]));
            }

            return new RemoteResponse($this->status, hash('sha256', $this->validator));
        }
    };
}

it('installs initially then skips unchanged releases without GET', function () {
    $transport = updateTransport();
    app()->instance(Transport::class, $transport);
    $first = app(DatabaseUpdater::class)->run(['country']);
    expect($first[0]->status)->toBe('updated')->and($first[0]->installed)->toBeTrue();
    $second = app(DatabaseUpdater::class)->run(['country']);
    expect($second[0]->status)->toBe('up_to_date')->and($transport->methods)->toBe(['HEAD', 'GET', 'HEAD']);
    expect(app(IpLookup::class)->lookup('8.8.8.8')->countryCode)->toBe('HU');
});

it('replaces manually installed V1 data and retained lookup sees it', function () {
    copy(__DIR__.'/../../Fixtures/country.mmdb', $this->directory.'/country.mmdb');
    $lookup = app(IpLookup::class);
    expect($lookup->lookup('8.8.8.8')->countryCode)->toBe('HU');
    app()->instance(Transport::class, updateTransport('country-next'));
    expect(app(DatabaseUpdater::class)->run(['country'])[0]->status)->toBe('updated')
        ->and($lookup->lookup('8.8.8.8')->countryCode)->toBe('DE');
});

it('does not replace old bytes after a wrong-type candidate or downgrade even with force', function ($fixture) {
    copy(__DIR__.'/../../Fixtures/country-next.mmdb', $this->directory.'/country.mmdb');
    $hash = hash_file('sha256', $this->directory.'/country.mmdb');
    app()->instance(Transport::class, updateTransport($fixture));
    $result = app(DatabaseUpdater::class)->run(['country'], force: true)[0];
    expect($result->status)->toBe('failed')->and($result->installed)->toBeFalse()
        ->and(hash_file('sha256', $this->directory.'/country.mmdb'))->toBe($hash);
})->with(['wrong-type', 'country']);

it('check only makes HEAD and does not create or change files', function () {
    $transport = updateTransport();
    app()->instance(Transport::class, $transport);
    expect(app(DatabaseUpdater::class)->run(['country'], check: true)[0]->status)->toBe('update_available')
        ->and($transport->methods)->toBe(['HEAD'])
        ->and(iterator_count(new FilesystemIterator($this->directory)))->toBe(0);
});

it('repairs missing files despite previous state', function () {
    $transport = updateTransport();
    app()->instance(Transport::class, $transport);
    app(DatabaseUpdater::class)->run(['country']);
    unlink($this->directory.'/country.mmdb');
    expect(app(DatabaseUpdater::class)->run(['country'])[0]->status)->toBe('updated')
        ->and($transport->methods)->toBe(['HEAD', 'GET', 'HEAD', 'GET']);
});

it('does not rename identical bytes even with force', function () {
    copy(__DIR__.'/../../Fixtures/country.mmdb', $this->directory.'/country.mmdb');
    $inode = fileinode($this->directory.'/country.mmdb');
    app()->instance(Transport::class, updateTransport());
    $result = app(DatabaseUpdater::class)->run(['country'], force: true)[0];
    clearstatcache();
    expect($result->status)->toBe('up_to_date')->and($result->installed)->toBeFalse()
        ->and(fileinode($this->directory.'/country.mmdb'))->toBe($inode);
});

it('honors target locks and releases them after errors', function () {
    $storage = app(UpdateStorage::class);
    $target = $this->directory.'/country.mmdb';
    $lock = $storage->lock($target, false, app(UpdateOptions::class));
    $transport = updateTransport();
    app()->instance(Transport::class, $transport);
    try {
        expect(app(DatabaseUpdater::class)->run(['country'])[0]->status)->toBe('busy')
            ->and($transport->methods)->toBe([]);
    } finally {
        $storage->unlock($lock);
    }
    app()->instance(Transport::class, updateTransport(status: 403));
    expect(app(DatabaseUpdater::class)->run(['country'])[0]->errorCode)->toBe('authentication_failed');
    app()->instance(Transport::class, $transport);
    expect(app(DatabaseUpdater::class)->run(['country'])[0]->status)->toBe('updated');
});

it('persists long retry cooldown and does not request again even with force', function () {
    $transport = Mockery::mock(Transport::class);
    $retryAt = time() + 3600;
    $transport->shouldReceive('request')->once()->andReturn(new RemoteResponse(429, retryAt: $retryAt));
    app()->instance(Transport::class, $transport);
    $first = app(DatabaseUpdater::class)->run(['country'])[0];
    $second = app(DatabaseUpdater::class)->run(['country'], force: true)[0];
    expect($first->errorCode)->toBe('rate_limited')->and($second->errorCode)->toBe('cooldown')
        ->and($second->nextRetryAt)->toBe($retryAt);
});

it('check does not persist cooldown or create lock files', function () {
    $transport = Mockery::mock(Transport::class);
    $transport->shouldReceive('request')->twice()->andReturn(new RemoteResponse(429, retryAt: time() + 3600));
    app()->instance(Transport::class, $transport);
    app(DatabaseUpdater::class)->run(['country'], check: true);
    app(DatabaseUpdater::class)->run(['country'], check: true);
    expect(iterator_count(new FilesystemIterator($this->directory)))->toBe(0);
});

it('retries transient errors only within the small configured budget', function () {
    $real = updateTransport();
    $transport = Mockery::mock(Transport::class);
    $transport->shouldReceive('request')->once()->ordered()->andReturn(new RemoteResponse(503));
    $transport->shouldReceive('request')->once()->ordered()->andReturn(new RemoteResponse(200, hash('sha256', 'a')));
    $transport->shouldReceive('request')->once()->ordered()->andReturnUsing(fn (...$args) => $real->request(...$args));
    $sleeper = Mockery::mock(Sleeper::class);
    $sleeper->shouldReceive('pause')->once()->with(1);
    app()->instance(Transport::class, $transport);
    app()->instance(Sleeper::class, $sleeper);
    expect(app(DatabaseUpdater::class)->run(['country'])[0]->status)->toBe('updated');
});

it('uses conservative GET for missing validators or unsupported HEAD and rejects 304', function ($headStatus) {
    $real = updateTransport();
    $transport = Mockery::mock(Transport::class);
    $transport->shouldReceive('request')->with('HEAD', Mockery::any(), Mockery::any(), null)->once()->andReturn(new RemoteResponse($headStatus));
    if ($headStatus !== 304) {
        $transport->shouldReceive('request')->with('GET', Mockery::any(), Mockery::any(), Mockery::type('string'))->once()->andReturnUsing(fn (...$args) => $real->request(...$args));
    }
    app()->instance(Transport::class, $transport);
    expect(app(DatabaseUpdater::class)->run(['country'])[0]->status)->toBe($headStatus === 304 ? 'failed' : 'updated');
})->with([200, 405, 501, 304]);

it('recovers after database rename succeeds but state finalization fails', function () {
    $storage = new class extends UpdateStorage
    {
        public int $calls = 0;

        public function saveState(string $target, array $state): void
        {
            if (++$this->calls === 2) {
                throw new UpdateFailure('state_write_failed');
            }
            parent::saveState($target, $state);
        }
    };
    app()->instance(UpdateStorage::class, $storage);
    $transport = updateTransport();
    app()->instance(Transport::class, $transport);
    $result = app(DatabaseUpdater::class)->run(['country'])[0];
    expect($result->status)->toBe('failed')->and($result->installed)->toBeTrue()
        ->and($result->errorCode)->toBe('installed_metadata_failed')
        ->and(app(IpLookup::class)->lookup('8.8.8.8')->countryCode)->toBe('HU');
    expect(app(DatabaseUpdater::class)->run(['country'])[0]->status)->toBe('up_to_date')
        ->and($transport->methods)->toBe(['HEAD', 'GET', 'HEAD', 'GET']);
});

it('preserves old data if rename fails and cleans the staging files', function () {
    copy(__DIR__.'/../../Fixtures/country.mmdb', $this->directory.'/country.mmdb');
    $storage = new class extends UpdateStorage
    {
        public function install(string $candidate, string $target): void
        {
            throw new UpdateFailure('install_failed');
        }
    };
    app()->instance(UpdateStorage::class, $storage);
    app()->instance(Transport::class, updateTransport('country-next'));
    expect(app(DatabaseUpdater::class)->run(['country'])[0]->errorCode)->toBe('install_failed')
        ->and(app(IpLookup::class)->lookup('8.8.8.8')->countryCode)->toBe('HU')
        ->and(glob($storage->directory($this->directory.'/country.mmdb').'/stage-*'))->toBe([]);
});

it('keeps successful Country when ASN fails and returns explicit partial failure', function () {
    $real = updateTransport();
    $transport = Mockery::mock(Transport::class);
    $transport->shouldReceive('request')->andReturnUsing(function ($method, $url, $options, $sink) use ($real) {
        return str_contains($url, 'GeoLite2-ASN') ? new RemoteResponse(403) : $real->request($method, $url, $options, $sink);
    });
    app()->instance(Transport::class, $transport);
    expect(Artisan::call('ip-data:update', ['--json' => true]))->toBe(1);
    $data = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($data['partialFailure'])->toBeTrue()->and($data['results'][0]['status'])->toBe('updated')
        ->and($data['results'][1]['status'])->toBe('failed')
        ->and(app(IpLookup::class)->lookup('8.8.8.8')->countryCode)->toBe('HU');
});

it('does not reuse validators after a source change', function () {
    $transport = updateTransport();
    app()->instance(Transport::class, $transport);
    app(DatabaseUpdater::class)->run(['country']);
    config(['ip-analyzer.update.sources.country' => UpdateOptions::defaults()['sources']['country'].'&date=20261001']);
    app(DatabaseUpdater::class)->run(['country']);
    expect($transport->methods)->toBe(['HEAD', 'GET', 'HEAD', 'GET']);
});

it('keeps check byte-for-byte read-only with existing installed state', function () {
    $transport = updateTransport();
    app()->instance(Transport::class, $transport);
    app(DatabaseUpdater::class)->run(['country']);
    $directory = app(UpdateStorage::class)->directory($this->directory.'/country.mmdb');
    $state = file_get_contents($directory.'/state.json');
    $notices = file_get_contents($directory.'/notices/LICENSE.txt');
    $inode = fileinode($this->directory.'/country.mmdb');
    expect(app(DatabaseUpdater::class)->run(['country'], check: true)[0]->status)->toBe('up_to_date')
        ->and(file_get_contents($directory.'/state.json'))->toBe($state)
        ->and(file_get_contents($directory.'/notices/LICENSE.txt'))->toBe($notices)
        ->and(fileinode($this->directory.'/country.mmdb'))->toBe($inode)
        ->and($state)->not->toContain('synthetic-license', '12345', 'https://');
});

it('reports busy exit three without replacing any requested file', function () {
    $storage = app(UpdateStorage::class);
    $lock = $storage->lock($this->directory.'/country.mmdb', false, app(UpdateOptions::class));
    try {
        expect(Artisan::call('ip-data:update', ['--database' => ['country'], '--json' => true]))->toBe(3);
        expect(json_decode(Artisan::output(), true)['results'][0]['status'])->toBe('busy');
    } finally {
        $storage->unlock($lock);
    }
});

it('keeps completed Country and reports failure when ASN is busy', function () {
    $storage = app(UpdateStorage::class);
    $lock = $storage->lock($this->directory.'/asn.mmdb', false, app(UpdateOptions::class));
    app()->instance(Transport::class, updateTransport());
    try {
        expect(Artisan::call('ip-data:update', ['--json' => true]))->toBe(1);
        $result = json_decode(Artisan::output(), true);
        expect($result['partialFailure'])->toBeTrue()->and($result['results'][0]['installed'])->toBeTrue()
            ->and($result['results'][1]['status'])->toBe('busy');
    } finally {
        $storage->unlock($lock);
    }
});

it('recovers notices after a post-install notice error', function () {
    $storage = new class extends UpdateStorage
    {
        public bool $fail = true;

        public function saveNotices(string $target, array $notices): void
        {
            if ($this->fail) {
                $this->fail = false;
                throw new UpdateFailure('notice_write_failed');
            }
            parent::saveNotices($target, $notices);
        }
    };
    app()->instance(UpdateStorage::class, $storage);
    app()->instance(Transport::class, updateTransport());
    expect(app(DatabaseUpdater::class)->run(['country'])[0]->errorCode)->toBe('installed_metadata_failed');
    expect(app(DatabaseUpdater::class)->run(['country'])[0]->status)->toBe('up_to_date')
        ->and(file_get_contents($storage->directory($this->directory.'/country.mmdb').'/notices/LICENSE.txt'))->toBe('Synthetic MIT');
});

it('compares common HEAD GET validators when only GET provides ETag', function () {
    $modified = 'Wed, 15 Nov 2023 00:00:00 GMT';
    $archive = syntheticArchive([syntheticTarEntry('GeoLite2-Country.mmdb', file_get_contents(__DIR__.'/../../Fixtures/country.mmdb'))]);
    $handler = HandlerStack::create(new MockHandler([
        new Response(200, ['Last-Modified' => $modified]),
        new Response(200, ['Last-Modified' => $modified, 'ETag' => '"file-version"'], $archive),
        new Response(200, ['Last-Modified' => $modified]),
    ]));
    app()->instance(Transport::class, new GuzzleTransport($handler));
    expect(app(DatabaseUpdater::class)->run(['country'])[0]->status)->toBe('updated');
    expect(app(DatabaseUpdater::class)->run(['country'])[0]->status)->toBe('up_to_date');
});

it('cleans only aged owned staging while holding the target lock', function () {
    $storage = app(UpdateStorage::class);
    $target = $this->directory.'/country.mmdb';
    $lock = $storage->lock($target, false, app(UpdateOptions::class));
    $root = $storage->directory($target);
    $aged = $root.'/stage-'.str_repeat('a', 32);
    $recent = $root.'/stage-'.str_repeat('b', 32);
    mkdir($aged, 0700);
    mkdir($recent, 0700);
    file_put_contents($aged.'/download', 'partial');
    touch($aged, time() - 90000);
    $orphan = $root.'/.state-'.str_repeat('c', 32);
    file_put_contents($orphan, 'partial');
    touch($orphan, time() - 90000);
    try {
        $stage = $storage->stage($target, app(UpdateOptions::class));
        expect(is_dir($aged))->toBeFalse()->and(is_file($orphan))->toBeFalse()->and(is_dir($recent))->toBeTrue();
        $storage->cleanup($stage);
    } finally {
        $storage->unlock($lock);
    }
});

it('preserves existing bytes and sanitized CLI output after an interrupted download', function () {
    copy(__DIR__.'/../../Fixtures/country.mmdb', $this->directory.'/country.mmdb');
    $before = hash_file('sha256', $this->directory.'/country.mmdb');
    $transport = Mockery::mock(Transport::class);
    $transport->shouldReceive('request')->with('HEAD', Mockery::any(), Mockery::any(), null)->once()->andReturn(new RemoteResponse(200));
    $transport->shouldReceive('request')->with('GET', Mockery::any(), Mockery::any(), Mockery::type('string'))->once()->andReturnUsing(function ($method, $url, $options, $sink) {
        file_put_contents($sink, 'partial');
        throw new UpdateFailure('transport_failed');
    });
    app()->instance(Transport::class, $transport);
    config(['app.debug' => true]);
    expect(Artisan::call('ip-data:update', ['--database' => ['country'], '--json' => true, '-vvv' => true]))->toBe(1);
    $output = Artisan::output();
    expect(json_decode($output, true, flags: JSON_THROW_ON_ERROR)['results'][0]['installed'])->toBeFalse()
        ->and($output)->not->toContain('synthetic-license', '12345')
        ->and(hash_file('sha256', $this->directory.'/country.mmdb'))->toBe($before);
    $storage = app(UpdateStorage::class);
    expect(glob($storage->directory($this->directory.'/country.mmdb').'/stage-*'))->toBe([]);
});

it('preserves downgrade protection for a manually installed GeoIP2 Country database', function () {
    $bytes = file_get_contents(__DIR__.'/../../Fixtures/country-next.mmdb');
    $bytes = str_replace(chr(64 + strlen('GeoLite2-Country')).'GeoLite2-Country', chr(64 + strlen('GeoIP2-Country')).'GeoIP2-Country', $bytes);
    file_put_contents($this->directory.'/country.mmdb', $bytes);
    app()->instance(Transport::class, updateTransport('country'));
    $result = app(DatabaseUpdater::class)->run(['country'], force: true)[0];
    expect($result->errorCode)->toBe('downgrade_rejected')
        ->and(file_get_contents($this->directory.'/country.mmdb'))->toBe($bytes);
});
