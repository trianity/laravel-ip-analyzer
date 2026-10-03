<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Support;

use Composer\InstalledVersions;

final class PackageVersion
{
    public static function get(): string
    {
        $name = 'trianity/laravel-ip-analyzer';
        if (InstalledVersions::isInstalled($name)) {
            $version = InstalledVersions::getPrettyVersion($name);
            if ($version !== null) {
                return $version;
            }
        }
        $root = InstalledVersions::getRootPackage();

        return ($root['name'] ?? null) === $name
            ? ($root['pretty_version'] ?? 'development')
            : 'development';
    }
}
