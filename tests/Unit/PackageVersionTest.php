<?php

use Composer\InstalledVersions;
use Trianity\IpAnalyzer\Support\PackageVersion;

it('handles installed root and missing package metadata', function ($data, $expected) {
    $original = InstalledVersions::getRawData();
    $vendors = new ReflectionProperty(InstalledVersions::class, 'canGetVendors');
    $oldVendors = $vendors->getValue();
    $vendors->setValue(null, false);
    try {
        InstalledVersions::reload($data);
        expect(PackageVersion::get())->toBe($expected);
    } finally {
        InstalledVersions::reload($original);
        $vendors->setValue(null, $oldVendors);
    }
})->with([
    [['root' => ['name' => 'other/app'], 'versions' => ['trianity/laravel-ip-analyzer' => ['pretty_version' => 'v9.8.7', 'version' => '9.8.7.0']]], 'v9.8.7'],
    [['root' => ['name' => 'trianity/laravel-ip-analyzer', 'pretty_version' => 'dev-test'], 'versions' => []], 'dev-test'],
    [['root' => ['name' => 'other/app'], 'versions' => []], 'development'],
]);
