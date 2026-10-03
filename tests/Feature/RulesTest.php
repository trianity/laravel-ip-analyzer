<?php

use Trianity\IpAnalyzer\Contracts\IpAnalyzer;
use Trianity\IpAnalyzer\Contracts\IpLookup;
use Trianity\IpAnalyzer\Contracts\Rule;
use Trianity\IpAnalyzer\Data\IpFacts;
use Trianity\IpAnalyzer\Data\RuleMatch;
use Trianity\IpAnalyzer\Enums\LookupStatus;
use Trianity\IpAnalyzer\Enums\Severity;

function observation(string $id, mixed $value): array
{
    return ['id' => $id, 'value' => $value, 'reason_code' => 'observed', 'severity' => 'warning', 'message' => 'Synthetic observation'];
}

it('looks up exactly once and returns every match in configured group order', function () {
    $lookup = Mockery::mock(IpLookup::class);
    $lookup->shouldReceive('lookup')->once()->with('8.8.8.8')->andReturn(new IpFacts('8.8.8.8', LookupStatus::Found, LookupStatus::Found, 'HU', 64512));
    app()->instance(IpLookup::class, $lookup);
    config(['ip-analyzer.rules' => [
        'ip_cidr' => [observation('ip', '8.8.8.0/24')],
        'asn' => [observation('asn', 64512)],
        'country' => [observation('country', 'hu')],
    ]]);
    expect(array_map(fn ($m) => $m->ruleId, app(IpAnalyzer::class)->analyze('8.8.8.8')->matches))->toBe(['ip', 'asn', 'country']);
});

it('has no implicit safe result or rules', function () {
    expect(app(IpAnalyzer::class)->analyze('127.0.0.1')->matches)->toBe([]);
});

it('compares binary CIDR boundaries including non-public addresses', function ($range, $ip, $matches) {
    config(['ip-analyzer.rules.ip_cidr' => [observation('range', $range)]]);
    expect(count(app(IpAnalyzer::class)->analyze($ip)->matches))->toBe($matches);
})->with([
    ['0.0.0.0/0', '192.0.2.1', 1], ['::/0', '2001:db8::1', 1],
    ['192.0.2.1/32', '192.0.2.1', 1], ['192.0.2.1/32', '192.0.2.2', 0],
    ['2001:db8::1/128', '2001:db8::1', 1], ['2001:db8::1/128', '2001:db8::2', 0],
    ['192.0.2.128/25', '192.0.2.127', 0], ['192.0.2.128/25', '192.0.2.128', 1],
    ['192.0.2.128/25', '192.0.2.255', 1], ['192.0.2.128/25', '192.0.3.0', 0],
    ['2001:db8::/127', '2001:db8::1', 1], ['2001:db8::/127', '2001:db8::2', 0],
    ['::/0', '192.0.2.1', 0], ['0.0.0.0/0', '2001:db8::1', 0],
    ['127.0.0.1', '::ffff:127.0.0.1', 1], ['0.0.0.0/0', 'invalid', 0],
]);

it('does not evaluate unknown country or ASN values', function () {
    config(['ip-analyzer.rules.asn' => [observation('asn', 64512)], 'ip-analyzer.rules.country' => [observation('country', 'HU')]]);
    $lookup = Mockery::mock(IpLookup::class);
    $lookup->shouldReceive('lookup')->andReturn(new IpFacts('8.8.8.8', LookupStatus::Unavailable, LookupStatus::NotFound, 'HU', 64512));
    app()->instance(IpLookup::class, $lookup);
    expect(app(IpAnalyzer::class)->analyze('8.8.8.8')->matches)->toBe([]);
});

it('rejects malformed rule configuration', function ($group, $entry) {
    config(["ip-analyzer.rules.$group" => [$entry]]);
    expect(fn () => app(IpAnalyzer::class)->analyze('127.0.0.1'))->toThrow(InvalidArgumentException::class);
})->with([
    ['asn', observation('bad', 0)], ['asn', observation('bad', '64512')],
    ['country', observation('bad', 'HUN')], ['ip_cidr', observation('bad', 'example.com')],
    ['ip_cidr', observation('bad', '1.1.1.1/33')], ['ip_cidr', observation('bad', '::/129')],
    ['ip_cidr', observation('bad', '1.1.1.1/-1')], ['ip_cidr', observation('bad', '1.1.1.1/abc')],
    ['asn', array_replace(observation('bad', 1), ['severity' => 'critical'])],
    ['asn', ['id' => 'incomplete']], ['asn', observation('', 1)],
]);

it('rejects duplicate identifiers across groups', function () {
    config(['ip-analyzer.rules.asn' => [observation('same', 1)], 'ip-analyzer.rules.country' => [observation('same', 'HU')]]);
    expect(fn () => app(IpAnalyzer::class)->analyze('127.0.0.1'))->toThrow(InvalidArgumentException::class);
});

class RuleDependency {}
class InjectedRule implements Rule
{
    public function __construct(public RuleDependency $dependency) {}

    public function id(): string
    {
        return 'custom';
    }

    public function evaluate(IpFacts $facts): ?RuleMatch
    {
        return new RuleMatch($this->id(), 'test', Severity::Info, 'Test');
    }
}
class WrongIdRule extends InjectedRule
{
    public function evaluate(IpFacts $facts): ?RuleMatch
    {
        return new RuleMatch('wrong', 'test', Severity::Info, 'Test');
    }
}
class ThrowingRule extends InjectedRule
{
    public function evaluate(IpFacts $facts): ?RuleMatch
    {
        throw new RuntimeException('Custom failure');
    }
}

it('resolves ordered custom rules with constructor injection', function () {
    config(['ip-analyzer.custom_rules' => [InjectedRule::class]]);
    expect(app(IpAnalyzer::class)->analyze('127.0.0.1')->matches[0]->ruleId)->toBe('custom');
});

it('rejects invalid custom rules and mismatched identifiers', function ($class) {
    config(['ip-analyzer.custom_rules' => [$class]]);
    expect(fn () => app(IpAnalyzer::class)->analyze('127.0.0.1'))->toThrow(InvalidArgumentException::class);
})->with(['MissingRule', stdClass::class, WrongIdRule::class]);

it('propagates custom rule failures', function () {
    config(['ip-analyzer.custom_rules' => [ThrowingRule::class]]);
    expect(fn () => app(IpAnalyzer::class)->analyze('127.0.0.1'))->toThrow(RuntimeException::class, 'Custom failure');
});

it('rejects malformed group and custom list structures', function ($key, $value) {
    config(["ip-analyzer.$key" => $value]);
    expect(fn () => app(IpAnalyzer::class)->analyze('127.0.0.1'))->toThrow(InvalidArgumentException::class);
})->with([
    ['rules', null], ['rules', ['unknown' => []]], ['rules.asn', null],
    ['rules.asn', ['named' => observation('test', 1)]],
    ['custom_rules', null], ['custom_rules', [new stdClass]],
    ['custom_rules', ['named' => InjectedRule::class]],
]);

it('rejects duplicate custom and configured identifiers', function () {
    config(['ip-analyzer.rules.asn' => [observation('custom', 1)], 'ip-analyzer.custom_rules' => [InjectedRule::class]]);
    expect(fn () => app(IpAnalyzer::class)->analyze('127.0.0.1'))->toThrow(InvalidArgumentException::class);
});

class NullRule extends InjectedRule
{
    public function id(): string
    {
        return 'no-observation';
    }

    public function evaluate(IpFacts $facts): ?RuleMatch
    {
        return null;
    }
}
class SecondRule extends InjectedRule
{
    public function id(): string
    {
        return 'second';
    }
}

it('continues after null and preserves multiple custom rule order', function () {
    config(['ip-analyzer.custom_rules' => [SecondRule::class, NullRule::class, InjectedRule::class]]);
    expect(array_map(fn ($m) => $m->ruleId, app(IpAnalyzer::class)->analyze('127.0.0.1')->matches))->toBe(['second', 'custom']);
});
