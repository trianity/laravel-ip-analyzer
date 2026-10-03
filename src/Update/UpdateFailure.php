<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update;

use RuntimeException;

final class UpdateFailure extends RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly ?int $retryAt = null)
    {
        parent::__construct($errorCode);
    }
}
