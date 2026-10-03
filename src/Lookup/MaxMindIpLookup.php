<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Lookup;

use LogicException;
use Trianity\IpAnalyzer\Contracts\IpLookup;
use Trianity\IpAnalyzer\Data\IpFacts;

/** Scaffold only: replace this stub using RED/GREEN/REFACTOR. */
final class MaxMindIpLookup implements IpLookup
{
    public function lookup(string $ip): IpFacts
    {
        throw new LogicException('Skeleton: local MMDB lookup is not implemented yet.');
    }
}
