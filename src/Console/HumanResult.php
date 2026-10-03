<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Console;

use Trianity\IpAnalyzer\Support\Messages;
use Trianity\IpAnalyzer\Update\UpdateOptions;

/** @internal Adds presentation labels without translating machine codes or custom rules. */
final class HumanResult
{
    public function __construct(private readonly Messages $messages) {}

    /** @param array<string, mixed> $data
     * @return list<string>
     */
    public function lines(array $data): array
    {
        $lines = [];
        if (isset($data['error'])) {
            $lines[] = $this->messages->get('errors.'.$data['error']);
        }
        foreach (['country', 'asn'] as $source) {
            $row = $data[$source] ?? null;
            if (isset($data['facts'])) {
                $facts = $data['facts'];
                $row = ['status' => $facts[$source.'Status'], 'errorCode' => $facts[$source.'ErrorCode'], 'metadata' => $facts[$source.'Database']];
            }
            if (is_array($row)) {
                $lines = array_merge($lines, $this->source($source, $row));
            }
        }
        foreach ($data['results'] ?? [] as $row) {
            $lines = array_merge($lines, $this->source($row['database'], $row));
        }

        return $lines;
    }

    public function configuration(string $message): string
    {
        // Whitelist existing fixed exception messages; never translate upstream text.
        $key = match ($message) {
            'schedule_enabled must be boolean.' => 'schedule',
            'Invalid update host allowlist.' => 'hosts',
            'Only reviewed MaxMind/R2 hostnames may be allowed.' => 'hostnames',
            'Invalid download sources.' => 'sources',
            'Invalid source permalink.' => 'permalink',
            'MaxMind Account ID and License Key are required.' => 'credentials',
            'Update targets must be absolute local regular-file paths.' => 'paths',
            'Update targets must be outside the public directory.' => 'public',
            'Country and ASN targets must be distinct.' => 'distinct',
            'Cannot resolve target directory.' => 'directory',
            'Database must be country or asn.' => 'selection',
            default => 'invalid',
        };
        if (preg_match('/^Invalid update option: ([a-z_]+)\.$/D', $message, $match) && array_key_exists($match[1], UpdateOptions::defaults())) {
            return $this->messages->get('configuration.option', ['option' => $match[1]]);
        }

        return $this->messages->get('configuration.'.$key);
    }

    /** @param array<string, mixed> $row
     * @return list<string>
     */
    private function source(string $source, array $row): array
    {
        $name = $this->messages->get('databases.'.$source);
        $lines = [$this->messages->get('result.source', ['database' => $name, 'status' => $this->messages->get('statuses.'.$row['status'])])];
        $keys = [];
        if (isset($row['errorCode'])) {
            $keys[] = 'errors.'.$row['errorCode'];
        }
        if (isset($row['warning'])) {
            $keys[] = 'warnings.'.$row['warning'];
        }
        if ($row['metadata']['stale'] ?? false) {
            $keys[] = 'warnings.stale';
        }
        if ($row['metadata']['futureBuild'] ?? false) {
            $keys[] = 'warnings.future_build';
        }
        foreach ($keys as $key) {
            $lines[] = $this->messages->get('result.detail', ['database' => $name, 'message' => $this->messages->get($key)]);
        }

        return $lines;
    }
}
