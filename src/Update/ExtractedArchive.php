<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update;

final readonly class ExtractedArchive
{
    /** @param array<string, string> $notices */
    public function __construct(public string $database, public array $notices) {}
}
