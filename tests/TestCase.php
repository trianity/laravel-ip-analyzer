<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Trianity\IpAnalyzer\IpAnalyzerServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [IpAnalyzerServiceProvider::class];
    }
}
