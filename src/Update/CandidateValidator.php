<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update;

use MaxMind\Db\Reader;
use MaxMind\Db\Reader\InvalidDatabaseException;
use Trianity\IpAnalyzer\Enums\LookupStatus;
use Trianity\IpAnalyzer\Lookup\LocalDatabase;
use Trianity\IpAnalyzer\Support\Clock;
use Trianity\IpAnalyzer\Update\Progress\Phase;
use Trianity\IpAnalyzer\Update\Progress\Progress;

final class CandidateValidator
{
    public function __construct(private readonly LocalDatabase $databases, private readonly Clock $clock, private readonly Progress $progress = new Progress) {}

    public function validate(string $source, string $path, UpdateOptions $options, bool $requireEdition = true): ValidatedDatabase
    {
        $this->progress->start($requireEdition ? Phase::CandidateValidation : Phase::LocalValidation, $source);
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
                    $this->progress->checkpoint();
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
                    if ($count % 256 === 0) {
                        // The SDK yields disjoint CIDR ranges. MMDB nodeCount is
                        // not a total for these iterations, so progress remains
                        // intentionally indeterminate without a second full pass.
                        $this->progress->advance($count);
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
                $this->progress->advance($count, force: true);
            } finally {
                $reader->close();
            }
            $file = @fopen($path, 'rb');
            if ($file === false) {
                throw new UpdateFailure('disk_error');
            }
            try {
                $stat = fstat($file);
                $this->progress->start(Phase::Hash, $source, $stat === false ? null : $stat['size']);
                $context = hash_init('sha256');
                $bytes = 0;
                while (! feof($file)) {
                    $this->progress->checkpoint();
                    $chunk = fread($file, 1048576);
                    if ($chunk === false || ($chunk === '' && ! feof($file))) {
                        throw new UpdateFailure('disk_error');
                    }
                    hash_update($context, $chunk);
                    $bytes += strlen($chunk);
                    $this->progress->advance($bytes);
                }
                $hash = hash_final($context);
                $this->progress->advance($bytes, force: true);
            } finally {
                fclose($file);
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
