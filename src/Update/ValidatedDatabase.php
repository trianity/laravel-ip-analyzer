<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update;

final readonly class ValidatedDatabase
{
    public function __construct(public int $buildEpoch, public string $sha256) {}
}
