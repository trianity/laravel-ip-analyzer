<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Contracts;

use Trianity\IpAnalyzer\Data\IpFacts;

interface IpLookup
{
    public function lookup(string $ip): IpFacts;
}
