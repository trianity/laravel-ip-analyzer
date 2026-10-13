<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update;

use Cron\CronExpression;
use DateTimeZone;
use Exception;
use Illuminate\Contracts\Config\Repository;
use Trianity\IpAnalyzer\Support\Messages;

final readonly class ScheduleConfiguration
{
    public const DEFAULT_CRON = '15 3 * * *';

    public const DEFAULT_TIMEZONE = 'UTC';

    public function __construct(
        public bool $enabled,
        public string $cron,
        public string $timezone,
    ) {}

    public static function fromConfig(Repository $config, Messages $messages): self
    {
        $enabled = $config->get('ip-analyzer.update.schedule_enabled', false);
        if (! is_bool($enabled)) {
            throw new ScheduleConfigurationException($messages->get('configuration.schedule_enabled'), 'schedule_enabled');
        }

        $cron = $config->get('ip-analyzer.update.schedule_cron', self::DEFAULT_CRON);
        $timezone = $config->get('ip-analyzer.update.schedule_timezone', self::DEFAULT_TIMEZONE);
        if (! $enabled) {
            return new self(
                false,
                is_string($cron) ? $cron : self::DEFAULT_CRON,
                is_string($timezone) ? $timezone : self::DEFAULT_TIMEZONE,
            );
        }
        if (! is_string($cron) || ! CronExpression::isValidExpression($cron)) {
            throw new ScheduleConfigurationException($messages->get('configuration.schedule_cron'), 'schedule_cron');
        }
        if (! is_string($timezone) || ! self::validTimezone($timezone)) {
            throw new ScheduleConfigurationException($messages->get('configuration.schedule_timezone'), 'schedule_timezone');
        }

        return new self(true, $cron, $timezone);
    }

    /** @return array{enabled: bool, cron: string, timezone: string, valid: true} */
    public function status(): array
    {
        return [
            'enabled' => $this->enabled,
            'cron' => $this->cron,
            'timezone' => $this->timezone,
            'valid' => true,
        ];
    }

    /** @return array{enabled: bool|null, cron: string, timezone: string, valid: false, errorCode: string} */
    public static function invalidStatus(Repository $config, string $errorCode): array
    {
        $enabled = $config->get('ip-analyzer.update.schedule_enabled', false);
        $cron = $config->get('ip-analyzer.update.schedule_cron', self::DEFAULT_CRON);
        $timezone = $config->get('ip-analyzer.update.schedule_timezone', self::DEFAULT_TIMEZONE);

        return [
            'enabled' => is_bool($enabled) ? $enabled : null,
            'cron' => is_string($cron) ? $cron : self::DEFAULT_CRON,
            'timezone' => is_string($timezone) ? $timezone : self::DEFAULT_TIMEZONE,
            'valid' => false,
            'errorCode' => $errorCode,
        ];
    }

    private static function validTimezone(string $timezone): bool
    {
        try {
            new DateTimeZone($timezone);

            return true;
        } catch (Exception) {
            return false;
        }
    }
}
