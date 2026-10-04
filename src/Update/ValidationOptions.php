<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update;

use Illuminate\Contracts\Config\Repository;

final class ValidationOptions
{
    /** @var array{workers: mixed, worker_timeout: mixed} */
    private array $values;

    public function __construct(Repository $config)
    {
        $configured = $config->get('ip-analyzer.validation', []);
        if (! is_array($configured) || array_diff(array_keys($configured), ['workers', 'worker_timeout'])) {
            throw new UpdateConfigurationException('Invalid validation configuration.');
        }
        $this->values = array_replace(['workers' => 1, 'worker_timeout' => 1800], $configured);
    }

    public function workers(mixed $override = null): int
    {
        return $this->positiveInteger($override ?? $this->values['workers'], 'workers');
    }

    public function timeout(): int
    {
        return $this->positiveInteger($this->values['worker_timeout'], 'worker_timeout');
    }

    private function positiveInteger(mixed $value, string $name): int
    {
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1 && strlen($value) < 20) {
            $value = (int) $value;
        }
        if (! is_int($value) || $value < 1) {
            throw new UpdateConfigurationException('Invalid validation option: '.$name.'.');
        }

        return $value;
    }
}
