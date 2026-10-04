<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update;

final class FreshnessEvaluator
{
    /** @param array<string, mixed> $state */
    public function evaluate(string $identity, string $edition, array $state, RemoteResponse $remote): FreshnessDecision
    {
        if (! $this->validState($identity, $edition, $state) || ! $remote->canCompare($state)) {
            return new FreshnessDecision('freshness_unknown');
        }

        return new FreshnessDecision($remote->matches($state) ? 'up_to_date' : 'update_available');
    }

    /** @param array<string, mixed> $state */
    private function validState(string $identity, string $edition, array $state): bool
    {
        return ($state['pending'] ?? true) === false
            && ($state['identity'] ?? null) === $identity
            && ($state['edition'] ?? null) === $edition
            && is_int($state['build_epoch'] ?? null)
            && $state['build_epoch'] > 0
            && is_string($state['sha256'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/D', $state['sha256']) === 1;
    }
}
