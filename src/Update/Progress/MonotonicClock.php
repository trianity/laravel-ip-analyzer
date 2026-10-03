<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update\Progress;

class MonotonicClock
{
    public function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
