<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update;

/** @internal */
final class ScheduleConfigurationException extends UpdateConfigurationException
{
    public function __construct(string $message, public readonly string $setting)
    {
        parent::__construct($message);
    }
}
