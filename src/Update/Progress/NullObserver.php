<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update\Progress;

final class NullObserver implements Observer
{
    public function report(Snapshot $snapshot): void {}
}
