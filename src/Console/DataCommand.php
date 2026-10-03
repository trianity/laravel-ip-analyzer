<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

abstract class DataCommand extends Command
{
    /** @param object|array<string, mixed> $data */
    protected function renderResult(object|array $data): void
    {
        $flags = JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES;
        if (! $this->option('json')) {
            $flags |= JSON_PRETTY_PRINT;
        }
        $this->output->writeln(json_encode($data, $flags), OutputInterface::OUTPUT_RAW);
    }

    protected function failed(): int
    {
        $this->renderResult(['error' => 'invalid_configuration_or_rule']);

        return 2;
    }
}
