<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Analysis;

use LogicException;
use Trianity\IpAnalyzer\Contracts\IpAnalyzer;
use Trianity\IpAnalyzer\Contracts\IpLookup;
use Trianity\IpAnalyzer\Data\AnalysisResult;

/** Scaffold only: dependency injection is ready, behavior is a TDD task. */
final class LocalIpAnalyzer implements IpAnalyzer
{
    public function __construct(private readonly IpLookup $lookup) {}

    public function analyze(string $ip): AnalysisResult
    {
        throw new LogicException('Skeleton: rule evaluation is not implemented yet.');
    }
}
