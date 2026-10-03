<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Analysis;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Trianity\IpAnalyzer\Contracts\IpAnalyzer;
use Trianity\IpAnalyzer\Contracts\IpLookup;
use Trianity\IpAnalyzer\Contracts\Rule;
use Trianity\IpAnalyzer\Data\AnalysisResult;
use Trianity\IpAnalyzer\Rules\ConfiguredRule;

final class LocalIpAnalyzer implements IpAnalyzer
{
    public function __construct(
        private readonly IpLookup $lookup,
        private readonly Repository $config,
        private readonly Container $container,
    ) {}

    public function analyze(string $ip): AnalysisResult
    {
        $rules = $this->rules();
        $facts = $this->lookup->lookup($ip);
        $matches = [];
        foreach ($rules as $id => $rule) {
            $match = $rule->evaluate($facts);
            if ($match !== null) {
                if ($match->ruleId !== (string) $id) {
                    throw new InvalidArgumentException('Rule match identifier differs from rule identifier.');
                }
                $matches[] = $match;
            }
        }

        return new AnalysisResult($facts, $matches);
    }

    /** @return array<string, Rule> */
    private function rules(): array
    {
        $groups = $this->config->get('ip-analyzer.rules');
        if (! is_array($groups) || array_diff(array_keys($groups), ['ip_cidr', 'asn', 'country']) !== []) {
            throw new InvalidArgumentException('Invalid rule groups.');
        }
        $rules = [];
        foreach (['ip_cidr', 'asn', 'country'] as $group) {
            $entries = array_key_exists($group, $groups) ? $groups[$group] : [];
            if (! is_array($entries) || ! array_is_list($entries)) {
                throw new InvalidArgumentException('Rule groups must contain lists.');
            }
            foreach ($entries as $entry) {
                $this->append($rules, new ConfiguredRule($group, $entry));
            }
        }
        $custom = $this->config->get('ip-analyzer.custom_rules');
        if (! is_array($custom) || ! array_is_list($custom)) {
            throw new InvalidArgumentException('Custom rules must be a list of class names.');
        }
        foreach ($custom as $class) {
            if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, Rule::class) || ! (new \ReflectionClass($class))->isInstantiable()) {
                throw new InvalidArgumentException('Custom rule must be an instantiable Rule class.');
            }
            $rule = $this->container->make($class);
            if (! $rule instanceof Rule) {
                throw new InvalidArgumentException('Resolved custom rule must implement Rule.');
            }
            $this->append($rules, $rule);
        }

        return $rules;
    }

    /** @param array<string, Rule> $rules */
    private function append(array &$rules, Rule $rule): void
    {
        $id = $rule->id();
        if (trim($id) === '' || array_key_exists($id, $rules)) {
            throw new InvalidArgumentException('Rule identifiers must be non-empty and unique.');
        }
        $rules[$id] = $rule;
    }
}
