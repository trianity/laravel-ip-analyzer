<?php

use Trianity\IpAnalyzer\Contracts\IpLookup;
use Trianity\IpAnalyzer\Update\UpdateOptions;

it('keeps offline use credential-free and supplies update defaults to old config', function () {
    config(['ip-analyzer.update' => []]);
    expect(app(UpdateOptions::class)->integer('max_redirects'))->toBe(3);
    expect(app(IpLookup::class)->lookup('127.0.0.1')->countryStatus->value)->toBe('non_public');
    expect(fn () => app(UpdateOptions::class)->credentials())->toThrow(InvalidArgumentException::class);
});

it('rejects invalid updater options', function ($key, $value) {
    config(["ip-analyzer.update.$key" => $value]);
    expect(fn () => app(UpdateOptions::class))->toThrow(InvalidArgumentException::class);
})->with([['timeout', 0], ['max_redirects', -1], ['retries', 20], ['max_bytes', '123'], ['schedule_enabled', 'yes'], ['allowed_hosts', ['evil.example']], ['max_expanded_bytes', -1]]);

it('requires distinct private local targets', function () {
    config(['ip-analyzer.databases.country' => '/tmp/same.mmdb', 'ip-analyzer.databases.asn' => '/tmp/same.mmdb']);
    expect(fn () => app(UpdateOptions::class)->targets())->toThrow(InvalidArgumentException::class);
});

it('rejects public paths and wrappers', function ($path) {
    config(['ip-analyzer.databases.country' => $path === 'public' ? public_path('db.mmdb') : $path]);
    expect(fn () => app(UpdateOptions::class)->targets())->toThrow(InvalidArgumentException::class);
})->with(['public', 'https://example.com/db.mmdb', 'relative.mmdb']);

it('canonicalizes symlink parents before resolving dot segments', function () {
    $root = sys_get_temp_dir().'/ip-path-'.bin2hex(random_bytes(8));
    mkdir($root.'/real/nested', 0700, true);
    symlink($root.'/real/nested', $root.'/alias');
    try {
        config(['ip-analyzer.databases.country' => $root.'/alias/../country.mmdb',
            'ip-analyzer.databases.asn' => $root.'/real/asn.mmdb']);
        expect(app(UpdateOptions::class)->targets()['country'])->toBe($root.'/real/country.mmdb');
    } finally {
        unlink($root.'/alias');
        rmdir($root.'/real/nested');
        rmdir($root.'/real');
        rmdir($root);
    }
});

it('supports a published V1 configuration through config cache', function () {
    $published = config_path('ip-analyzer.php');
    $old = [
        'databases' => ['country' => sys_get_temp_dir().'/v1-country.mmdb', 'asn' => sys_get_temp_dir().'/v1-asn.mmdb'],
        'max_age_days' => 30, 'rules' => ['ip_cidr' => [], 'asn' => [], 'country' => []], 'custom_rules' => [],
    ];
    file_put_contents($published, '<?php return '.var_export($old, true).';');
    try {
        $this->artisan('config:cache')->assertSuccessful();
        $this->refreshApplication();
        expect(app()->configurationIsCached())->toBeTrue()
            ->and(app(UpdateOptions::class)->integer('timeout'))->toBe(120)
            ->and(app(IpLookup::class)->lookup('127.0.0.1')->countryStatus->value)->toBe('non_public');
    } finally {
        $this->artisan('config:clear')->assertSuccessful();
        unlink($published);
    }
});
