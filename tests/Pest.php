<?php

declare(strict_types=1);
use Trianity\IpAnalyzer\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');

require_once __DIR__.'/Fixtures/update-archives.php';

require_once __DIR__.'/Fixtures/console-output.php';
