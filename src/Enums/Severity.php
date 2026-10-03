<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Enums;

enum Severity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case High = 'high';
}
