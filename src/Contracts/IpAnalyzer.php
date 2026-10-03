<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Contracts;

use Trianity\IpAnalyzer\Data\AnalysisResult;

interface IpAnalyzer
{
    public function analyze(string $ip): AnalysisResult;
}
