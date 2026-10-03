<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Tests\Feature;

use Trianity\IpAnalyzer\Contracts\IpAnalyzer;
use Trianity\IpAnalyzer\Contracts\IpLookup;
use Trianity\IpAnalyzer\Tests\TestCase;

final class ServiceProviderTest extends TestCase
{
    public function test_contracts_can_be_resolved(): void
    {
        self::assertInstanceOf(IpLookup::class, $this->app->make(IpLookup::class));
        self::assertInstanceOf(IpAnalyzer::class, $this->app->make(IpAnalyzer::class));
    }

    public function test_configuration_has_empty_rules_by_default(): void
    {
        self::assertSame([], config('ip-analyzer.custom_rules'));
        self::assertSame([], config('ip-analyzer.rules.asn'));
    }
}
