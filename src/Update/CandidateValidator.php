<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update;

use MaxMind\Db\Reader;
use MaxMind\Db\Reader\InvalidDatabaseException;
use Trianity\IpAnalyzer\Enums\LookupStatus;
use Trianity\IpAnalyzer\Lookup\LocalDatabase;
use Trianity\IpAnalyzer\Support\Clock;

final class CandidateValidator
{
    public function __construct(private readonly LocalDatabase $databases, private readonly Clock $clock) {}

    public function validate(string $source, string $path, UpdateOptions $options, bool $requireEdition = true): ValidatedDatabase
    {
        try {
            $inspection = $this->databases->inspectPath($source, $path);
            if ($inspection->status !== LookupStatus::Found || $inspection->metadata === null) {
                throw new UpdateFailure($inspection->errorCode ?? 'invalid_database');
            }
            $metadata = $inspection->metadata;
            if ($requireEdition && $metadata->databaseType !== UpdateOptions::EDITIONS[$source]) {
                throw new UpdateFailure('database_type_mismatch');
            }
            if ($metadata->buildEpoch <= 0 || $metadata->buildEpoch > $this->clock->now()) {
                throw new UpdateFailure('invalid_build_epoch');
            }
            $reader = new Reader($path);
            try {
                $raw = $reader->metadata();
                if (! in_array($raw->ipVersion, [4, 6], true) || $raw->binaryFormatMajorVersion !== 2
                    || ! in_array($raw->recordSize, [24, 28, 32], true) || $raw->nodeCount <= 0) {
                    throw new UpdateFailure('invalid_database');
                }
                $address = str_repeat("\0", $raw->ipVersion === 4 ? 4 : 16);
                $bits = strlen($address) * 8;
                $count = 0;
                do {
                    if (++$count > $options->integer('max_records')) {
                        throw new UpdateFailure('validation_limit');
                    }
                    [$record, $prefix] = $reader->getWithPrefixLen(inet_ntop($address));
                    if ($prefix < 0 || $prefix > $bits) {
                        throw new UpdateFailure('invalid_database');
                    }
                    if ($record !== null) {
                        $this->record($source, $record);
                    }
                    // Walk disjoint CIDR ranges using the SDK's prefix lengths. No live IP facts.
                    if ($prefix === 0) {
                        break;
                    }
                    $index = intdiv($prefix - 1, 8);
                    $carry = 1 << (7 - ($prefix - 1) % 8);
                    while ($index >= 0 && $carry > 0) {
                        $value = ord($address[$index]) + $carry;
                        $address[$index] = chr($value & 255);
                        $carry = $value > 255 ? 1 : 0;
                        $index--;
                    }
                } while ($carry === 0);
            } finally {
                $reader->close();
            }
            $hash = @hash_file('sha256', $path);
            if ($hash === false) {
                throw new UpdateFailure('disk_error');
            }

            return new ValidatedDatabase($metadata->buildEpoch, $hash);
        } catch (InvalidDatabaseException|\InvalidArgumentException|\UnexpectedValueException|\TypeError) {
            throw new UpdateFailure('invalid_database');
        }
    }

    private function record(string $source, mixed $record): void
    {
        if (! is_array($record)) {
            throw new UpdateFailure('invalid_database');
        }
        if ($source === 'country') {
            if (isset($record['country']) && ! is_array($record['country'])) {
                throw new UpdateFailure('invalid_database');
            }
            $code = $record['country']['iso_code'] ?? null;
            if ($code !== null && (! is_string($code) || ! preg_match('/^[A-Z]{2}$/D', $code))) {
                throw new UpdateFailure('invalid_database');
            }
        } else {
            $asn = $record['autonomous_system_number'] ?? null;
            $organization = $record['autonomous_system_organization'] ?? null;
            if (($asn !== null && (! is_int($asn) || $asn <= 0)) || ($organization !== null && ! is_string($organization))) {
                throw new UpdateFailure('invalid_database');
            }
        }
    }
}
