<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update\Progress;

interface Observer
{
    public function report(Snapshot $snapshot): void;
}
