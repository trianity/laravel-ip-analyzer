<?php

use Trianity\IpAnalyzer\Update\TarArchive;
use Trianity\IpAnalyzer\Update\UpdateFailure;
use Trianity\IpAnalyzer\Update\UpdateOptions;

beforeEach(function () {
    $this->stage = sys_get_temp_dir().'/ip-archive-'.bin2hex(random_bytes(8));
    mkdir($this->stage, 0700);
});
afterEach(function () {
    foreach (glob($this->stage.'/*') as $file) {
        unlink($file);
    }
    rmdir($this->stage);
});

it('extracts a single MMDB and preserves notices without extracting paths', function () {
    $db = file_get_contents(__DIR__.'/../../Fixtures/country.mmdb');
    file_put_contents($this->stage.'/download', syntheticArchive([
        syntheticTarEntry('release/GeoLite2-Country.mmdb', $db),
        syntheticTarEntry('release/LICENSE.txt', 'Synthetic MIT notice'),
    ]));
    $result = (new TarArchive)->extract($this->stage.'/download', $this->stage, 'GeoLite2-Country', app(UpdateOptions::class));
    expect(file_get_contents($result->database))->toBe($db)
        ->and(file_get_contents($result->notices['LICENSE.txt']))->toBe('Synthetic MIT notice');
});

it('rejects unsafe archive members including extended paths', function ($name, $type, $data) {
    file_put_contents($this->stage.'/download', syntheticArchive([
        syntheticTarEntry($name, $data, $type),
        syntheticTarEntry('GeoLite2-Country.mmdb', 'unused'),
    ]));
    expect(fn () => (new TarArchive)->extract($this->stage.'/download', $this->stage, 'GeoLite2-Country', app(UpdateOptions::class)))->toThrow(UpdateFailure::class);
})->with([
    ['../outside', '0', 'x'], ['/absolute', '0', 'x'], ['C:/windows', '0', 'x'],
    ['\\server\\file', '0', 'x'], ['link', '2', ''], ['hardlink', '1', ''],
    ['device', '3', ''], ['fifo', '6', ''],
    ['././@LongLink', 'L', "../outside\0"],
    ['pax', 'x', "19 path=../outside\n"],
]);

it('rejects CRC truncation duplicate MMDB and CSV ZIP', function ($kind) {
    $entry = syntheticTarEntry('GeoLite2-Country.mmdb', 'database');
    $data = syntheticArchive([$entry]);
    $data = match ($kind) {
        'crc' => substr_replace($data, str_repeat("\0", 8), -8),
        'truncated' => substr($data, 0, -10),
        'duplicate' => syntheticArchive([$entry, $entry]),
        'zip' => "PK\x03\x04csv-only",
    };
    file_put_contents($this->stage.'/download', $data);
    expect(fn () => (new TarArchive)->extract($this->stage.'/download', $this->stage, 'GeoLite2-Country', app(UpdateOptions::class)))->toThrow(UpdateFailure::class);
})->with(['crc', 'truncated', 'duplicate', 'zip']);

it('enforces the expanded byte limit', function () {
    config(['ip-analyzer.update.max_expanded_bytes' => 1024]);
    file_put_contents($this->stage.'/download', syntheticArchive([syntheticTarEntry('GeoLite2-Country.mmdb', str_repeat('a', 4096))]));
    expect(fn () => (new TarArchive)->extract($this->stage.'/download', $this->stage, 'GeoLite2-Country', app(UpdateOptions::class)))->toThrow(UpdateFailure::class, 'archive_too_large');
});

it('supports safe PAX and GNU long paths without trusting header names', function ($type) {
    $long = str_repeat('directory/', 15).'GeoLite2-Country.mmdb';
    $metadata = $type === 'x' ? syntheticPax('path', $long) : $long."\0";
    $database = file_get_contents(__DIR__.'/../../Fixtures/country.mmdb');
    file_put_contents($this->stage.'/download', syntheticArchive([
        syntheticTarEntry('metadata', $metadata, $type),
        syntheticTarEntry('short-name', $database),
    ]));
    $result = (new TarArchive)->extract($this->stage.'/download', $this->stage, 'GeoLite2-Country', app(UpdateOptions::class));
    expect(file_get_contents($result->database))->toBe($database);
})->with(['x', 'L']);

it('rejects well-formed PAX traversal and semantic overrides', function ($key, $value, $type) {
    file_put_contents($this->stage.'/download', syntheticArchive([
        syntheticTarEntry('metadata', syntheticPax($key, $value), $type),
        syntheticTarEntry('GeoLite2-Country.mmdb', 'data'),
    ]));
    expect(fn () => (new TarArchive)->extract($this->stage.'/download', $this->stage, 'GeoLite2-Country', app(UpdateOptions::class)))->toThrow(UpdateFailure::class);
})->with([['path', '../outside', 'x'], ['linkpath', '/outside', 'x'], ['GNU.sparse.map', '0,100', 'x'], ['path', 'global', 'g']]);

it('rejects entry overflow missing MMDB bad tar checksum and trailing gzip data', function ($case) {
    if ($case === 'entries') {
        config(['ip-analyzer.update.max_entries' => 1]);
    }
    $tar = syntheticTarEntry('README.txt', 'notice').syntheticTarEntry('GeoLite2-Country.mmdb', 'db');
    $archive = syntheticArchive([$tar]);
    if ($case === 'missing') {
        $archive = syntheticArchive([syntheticTarEntry('README.txt', 'notice')]);
    }
    if ($case === 'checksum') {
        $tar[0] = 'Z';
        $archive = syntheticArchive([$tar]);
    }
    if ($case === 'trailing') {
        $archive .= 'unexpected';
    }
    file_put_contents($this->stage.'/download', $archive);
    expect(fn () => (new TarArchive)->extract($this->stage.'/download', $this->stage, 'GeoLite2-Country', app(UpdateOptions::class)))->toThrow(UpdateFailure::class);
})->with(['entries', 'missing', 'checksum', 'trailing']);
