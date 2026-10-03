<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Enums;

enum LookupStatus: string
{
    case Found = 'found';
    case NotFound = 'not_found';
    case Unavailable = 'unavailable';
    case InvalidInput = 'invalid_input';
    case NonPublic = 'non_public';
}
