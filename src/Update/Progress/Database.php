<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update\Progress;

enum Database: string
{
    case Country = 'country';
    case Asn = 'asn';
}
