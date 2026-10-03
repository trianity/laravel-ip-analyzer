<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Lookup;

use GeoIp2\Exception\AddressNotFoundException;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;
use MaxMind\Db\Reader\InvalidDatabaseException;
use Trianity\IpAnalyzer\Data\DatabaseMetadata;
use Trianity\IpAnalyzer\Data\SourceResult;
use Trianity\IpAnalyzer\Enums\LookupStatus;
use Trianity\IpAnalyzer\Support\Clock;
use UnexpectedValueException;

final class LocalDatabase
{
    public function __construct(
        private readonly Repository $config,
        private readonly ReaderFactory $factory,
        private readonly Clock $clock,
    ) {}

    public function validateConfiguration(): void
    {
        $this->settings('country');
        $this->settings('asn');
    }

    /** @return array{string, int} */
    private function settings(string $source): array
    {
        $maxAge = $this->config->get('ip-analyzer.max_age_days');
        $path = $this->config->get('ip-analyzer.databases.'.$source);
        if (! is_int($maxAge) || $maxAge < 0 || $maxAge > intdiv(PHP_INT_MAX, 86400)) {
            throw new InvalidArgumentException('max_age_days must be a non-negative integer in range.');
        }
        if (! is_string($path) || $path === '' || str_contains($path, "\0") || str_contains($path, '://')) {
            throw new InvalidArgumentException('Database paths must be local file paths.');
        }

        return [$path, $maxAge];
    }

    public function read(string $source, ?string $ip = null): SourceResult
    {
        if (! in_array($source, ['country', 'asn'], true)) {
            throw new InvalidArgumentException('Unknown database source.');
        }
        [$path, $maxAge] = $this->settings($source);
        clearstatcache(true, $path);
        if (! is_file($path) || ! is_readable($path)) {
            return new SourceResult(LookupStatus::Unavailable, errorCode: 'file_unreadable');
        }
        try {
            $reader = $this->factory->open($path);
        } catch (InvalidArgumentException|UnexpectedValueException $e) {
            return new SourceResult(LookupStatus::Unavailable, errorCode: 'file_unreadable');
        } catch (InvalidDatabaseException $e) {
            return new SourceResult(LookupStatus::Unavailable, errorCode: 'invalid_database');
        }
        $metadata = null;
        try {
            $raw = $reader->metadata();
            $now = $this->clock->now();
            $age = max(0, $now - $raw->buildEpoch);
            $metadata = new DatabaseMetadata($raw->databaseType, $raw->buildEpoch, $age > $maxAge * 86400, $age / 86400, $raw->buildEpoch > $now);
            $types = $source === 'country' ? ['GeoLite2-Country', 'GeoIP2-Country'] : ['GeoLite2-ASN'];
            if (! in_array($raw->databaseType, $types, true)) {
                return new SourceResult(LookupStatus::Unavailable, $metadata, 'database_type_mismatch');
            }
            if ($ip === null) {
                return new SourceResult(LookupStatus::Found, $metadata);
            }
            if ($source === 'country') {
                $code = $reader->country($ip)->country->isoCode;
                if ($code === null) {
                    return new SourceResult(LookupStatus::NotFound, $metadata);
                }
                if (preg_match('/^[A-Z]{2}$/D', $code) !== 1) {
                    return new SourceResult(LookupStatus::Unavailable, $metadata, 'invalid_record');
                }

                return new SourceResult(LookupStatus::Found, $metadata, countryCode: $code);
            }
            $model = $reader->asn($ip);
            if ($model->autonomousSystemNumber === null) {
                return new SourceResult(LookupStatus::NotFound, $metadata);
            }
            if ($model->autonomousSystemNumber <= 0) {
                return new SourceResult(LookupStatus::Unavailable, $metadata, 'invalid_record');
            }

            return new SourceResult(LookupStatus::Found, $metadata, asn: $model->autonomousSystemNumber, asnOrganization: $model->autonomousSystemOrganization);
        } catch (AddressNotFoundException $e) {
            return new SourceResult(LookupStatus::NotFound, $metadata);
        } catch (InvalidDatabaseException $e) {
            return new SourceResult(LookupStatus::Unavailable, $metadata, 'invalid_database');
        } finally {
            $reader->close();
        }
    }
}
