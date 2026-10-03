<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update\Progress;

final class Interrupted extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('interrupted');
    }
}
