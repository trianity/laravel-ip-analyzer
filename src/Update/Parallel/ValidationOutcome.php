<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update\Parallel;

use Trianity\IpAnalyzer\Update\ValidatedDatabase;

final readonly class ValidationOutcome
{
    public function __construct(
        public string $taskId,
        public string $database,
        public ?ValidatedDatabase $result = null,
        public ?string $errorCode = null,
    ) {}
}
