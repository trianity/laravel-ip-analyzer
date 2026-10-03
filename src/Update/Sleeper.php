<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update;

class Sleeper
{
    public function pause(int $seconds): void
    {
        sleep($seconds);
    }
}
