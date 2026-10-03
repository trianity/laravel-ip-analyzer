<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Support;

class Clock
{
    public function now(): int
    {
        return time();
    }
}
