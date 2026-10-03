<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Lookup;

use GeoIp2\Database\Reader;

class ReaderFactory
{
    public function open(string $path): Reader
    {
        return new Reader($path);
    }
}
