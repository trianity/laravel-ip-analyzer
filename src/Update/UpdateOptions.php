<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update;

use Illuminate\Contracts\Config\Repository;
use Trianity\IpAnalyzer\Update\UpdateConfigurationException as InvalidArgumentException;

final class UpdateOptions
{
    public const ORIGIN = 'download.maxmind.com';

    public const R2 = 'mm-prod-geoip-databases.a2649acb697e2c09b632799562c076f2.r2.cloudflarestorage.com';

    public const EDITIONS = ['country' => 'GeoLite2-Country', 'asn' => 'GeoLite2-ASN'];

    /** @var array<string, mixed> */
    private array $values;

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'account_id' => null, 'license_key' => null,
            'connect_timeout' => 10, 'timeout' => 120, 'max_redirects' => 3,
            'retries' => 2, 'max_retry_wait' => 2, 'retry_delay' => 1,
            'max_bytes' => 134217728, 'max_expanded_bytes' => 536870912,
            'max_entries' => 100, 'max_records' => 5000000,
            'lock_timeout' => 0, 'cleanup_age' => 86400,
            'allowed_hosts' => [self::ORIGIN, self::R2],
            'schedule_enabled' => false,
            'sources' => [
                'country' => 'https://download.maxmind.com/geoip/databases/GeoLite2-Country/download?suffix=tar.gz',
                'asn' => 'https://download.maxmind.com/geoip/databases/GeoLite2-ASN/download?suffix=tar.gz',
            ],
        ];
    }

    public function __construct(private readonly Repository $config)
    {
        $configured = $config->get('ip-analyzer.update', []);
        if (! is_array($configured) || array_diff(array_keys($configured), array_keys(self::defaults()))) {
            throw new InvalidArgumentException('Invalid update configuration.');
        }
        $this->values = array_replace(self::defaults(), $configured);
        foreach (['connect_timeout' => [1, 120], 'timeout' => [1, 1800], 'max_redirects' => [0, 10],
            'retries' => [0, 3], 'max_retry_wait' => [0, 5], 'retry_delay' => [1, 60],
            'max_bytes' => [1, 1073741824], 'max_expanded_bytes' => [1, 2147483648],
            'max_entries' => [1, 10000], 'max_records' => [1, 20000000],
            'lock_timeout' => [0, 10], 'cleanup_age' => [3600, 31536000]] as $key => [$min, $max]) {
            if (! is_int($this->values[$key]) || $this->values[$key] < $min || $this->values[$key] > $max) {
                throw new InvalidArgumentException('Invalid update option: '.$key.'.');
            }
        }
        if (! is_bool($this->values['schedule_enabled'])) {
            throw new InvalidArgumentException('schedule_enabled must be boolean.');
        }
        $hosts = $this->values['allowed_hosts'];
        if (! is_array($hosts) || ! array_is_list($hosts) || ! in_array(self::ORIGIN, $hosts, true)) {
            throw new InvalidArgumentException('Invalid update host allowlist.');
        }
        foreach ($hosts as $host) {
            if (! is_string($host) || ! preg_match('/^(?:[a-z0-9]+(?:-[a-z0-9]+)*\.)+(?:maxmind\.com|r2\.cloudflarestorage\.com)$/D', $host)) {
                throw new InvalidArgumentException('Only reviewed MaxMind/R2 hostnames may be allowed.');
            }
        }
        $sources = $this->values['sources'];
        if (! is_array($sources) || array_diff(array_keys($sources), array_keys(self::EDITIONS))) {
            throw new InvalidArgumentException('Invalid download sources.');
        }
        $this->values['sources'] = array_replace(self::defaults()['sources'], $sources);
        foreach (self::EDITIONS as $name => $edition) {
            $url = $this->values['sources'][$name];
            if (! is_string($url)) {
                throw new InvalidArgumentException('Invalid source permalink.');
            }
            $parts = parse_url($url);
            parse_str($parts['query'] ?? '', $query);
            if (! $parts || ($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') !== self::ORIGIN
                || isset($parts['user'], $parts['pass']) || isset($parts['user']) || isset($parts['fragment'])
                || (isset($parts['port']) && $parts['port'] !== 443)
                || ($parts['path'] ?? '') !== '/geoip/databases/'.$edition.'/download'
                || ($query['suffix'] ?? '') !== 'tar.gz' || array_diff(array_keys($query), ['suffix', 'date'])
                || (isset($query['date']) && (! is_string($query['date']) || ! preg_match('/^[0-9]{8}$/D', $query['date'])))) {
                throw new InvalidArgumentException('Invalid source permalink.');
            }
        }
    }

    public function integer(string $key): int
    {
        return $this->values[$key];
    }

    /** @return list<string> */
    public function hosts(): array
    {
        return $this->values['allowed_hosts'];
    }

    public function url(string $database): string
    {
        return $this->values['sources'][$database];
    }

    /** @return array{string, string} */
    public function credentials(): array
    {
        $id = $this->values['account_id'];
        $key = $this->values['license_key'];
        if ((! is_string($id) && ! is_int($id)) || ! preg_match('/^[1-9][0-9]*$/D', (string) $id)
            || ! is_string($key) || $key === '' || preg_match('/[\x00-\x20\x7f]/', $key)) {
            throw new InvalidArgumentException('MaxMind Account ID and License Key are required.');
        }

        return [(string) $id, $key];
    }

    /** @return array<string, string> */
    public function targets(): array
    {
        $targets = [];
        foreach (self::EDITIONS as $source => $_) {
            $path = $this->config->get('ip-analyzer.databases.'.$source);
            if (! is_string($path) || ! str_starts_with($path, '/') || str_contains($path, '\\')
                || str_contains($path, "\0") || str_contains($path, '://') || is_link($path)) {
                throw new InvalidArgumentException('Update targets must be absolute local regular-file paths.');
            }
            $normalized = self::normalizePath($path);
            $public = self::normalizePath(public_path());
            if ($normalized === $public || str_starts_with($normalized, $public.'/') || is_dir($normalized)) {
                throw new InvalidArgumentException('Update targets must be outside the public directory.');
            }
            $targets[$source] = $normalized;
        }
        if ($targets['country'] === $targets['asn']
            || (is_file($targets['country']) && is_file($targets['asn'])
                && stat($targets['country'])['dev'] === stat($targets['asn'])['dev']
                && stat($targets['country'])['ino'] === stat($targets['asn'])['ino'])) {
            throw new InvalidArgumentException('Country and ASN targets must be distinct.');
        }

        return $targets;
    }

    private static function normalizePath(string $path): string
    {
        $resolved = '/';
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                $resolved = dirname($resolved);

                continue;
            }
            $resolved = rtrim($resolved, '/').'/'.$segment;
            if (is_link($resolved) && ! file_exists($resolved)) {
                throw new InvalidArgumentException('Cannot resolve target directory.');
            }
            if (file_exists($resolved)) {
                $real = realpath($resolved);
                if ($real === false) {
                    throw new InvalidArgumentException('Cannot resolve target directory.');
                }
                $resolved = $real;
            }
        }

        return $resolved;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['configuration' => '[redacted]'];
    }
}
