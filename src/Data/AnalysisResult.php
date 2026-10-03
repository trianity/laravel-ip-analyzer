<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Data;

final readonly class AnalysisResult
{
    /** @param list<RuleMatch> $matches */
    public function __construct(
        public IpFacts $facts,
        public array $matches,
    ) {}
}
