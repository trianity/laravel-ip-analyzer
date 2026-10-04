<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update;

use Trianity\IpAnalyzer\Enums\LookupStatus;
use Trianity\IpAnalyzer\Lookup\LocalDatabase;
use Trianity\IpAnalyzer\Update\Progress\Phase;
use Trianity\IpAnalyzer\Update\Progress\Progress;

final readonly class InstalledDatabaseInspector
{
    public function __construct(
        private LocalDatabase $databases,
        private Progress $progress,
    ) {}

    public function inspect(string $database, string $path): InstalledDatabaseInspection
    {
        $this->progress->start(Phase::LocalInspection, $database);
        $result = $this->databases->inspectPath($database, $path);
        $error = $result->errorCode;
        if ($result->status === LookupStatus::Found
            && $result->metadata?->databaseType !== UpdateOptions::EDITIONS[$database]) {
            $error = 'database_type_mismatch';
        }

        $status = 'usable';
        if (! is_file($path)) {
            $status = 'missing';
        } elseif ($error === 'file_unreadable') {
            $status = 'unreadable';
        } elseif ($error !== null) {
            $status = 'invalid';
        }

        return new InstalledDatabaseInspection(
            $database,
            $status,
            $result->metadata?->buildEpoch,
            $error,
        );
    }
}
