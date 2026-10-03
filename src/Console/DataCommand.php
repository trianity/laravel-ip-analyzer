<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Trianity\IpAnalyzer\Support\Messages;

abstract class DataCommand extends Command
{
    public function getDescription(): string
    {
        return app(Messages::class)->get('commands.'.substr($this->getName(), strlen('ip-data:')));
    }

    /** @param object|array<string, mixed> $data */
    protected function renderResult(object|array $data): void
    {
        $flags = JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES;
        if (! $this->option('json')) {
            $flags |= JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE;
            $data = json_decode(json_encode($data, $flags), true, flags: JSON_THROW_ON_ERROR);
            $human = app(HumanResult::class);
            foreach ($human->lines($data) as $line) {
                $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
            }
            if (($data['error'] ?? null) === 'invalid_update_configuration' && isset($data['message'])) {
                $data['message'] = $human->configuration($data['message']);
            }
        }
        $this->output->writeln(json_encode($data, $flags), OutputInterface::OUTPUT_RAW);
    }

    protected function failed(): int
    {
        $this->renderResult(['error' => 'invalid_configuration_or_rule']);

        return 2;
    }
}
