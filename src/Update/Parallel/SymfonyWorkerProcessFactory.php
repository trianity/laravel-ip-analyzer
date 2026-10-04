<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update\Parallel;

use Composer\Autoload\ClassLoader;
use Illuminate\Contracts\Config\Repository;
use ReflectionClass;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Trianity\IpAnalyzer\Support\Clock;
use Trianity\IpAnalyzer\Update\UpdateOptions;

final class SymfonyWorkerProcessFactory implements WorkerProcessFactory
{
    private ?string $php = null;

    private ?string $autoload = null;

    private readonly string $entry;

    public function __construct(
        private readonly Repository $config,
        private readonly UpdateOptions $options,
        private readonly Clock $clock,
    ) {
        $this->entry = __DIR__.'/worker.php';
    }

    public function supported(): bool
    {
        $php = (new PhpExecutableFinder)->find(false);
        $loader = (new ReflectionClass(ClassLoader::class))->getFileName();
        $autoload = is_string($loader) ? dirname($loader, 2).'/autoload.php' : '';
        if (! function_exists('proc_open') || ! is_string($php) || $php === '' || ! is_executable($php)
            || ! is_file($autoload) || ! is_readable($autoload) || ! is_file($this->entry) || ! is_readable($this->entry)) {
            return false;
        }
        $this->php = $php;
        $this->autoload = $autoload;

        return true;
    }

    public function create(ValidationTask $task, int $timeout): WorkerProcess
    {
        if ($this->php === null || $this->autoload === null) {
            throw new \LogicException('Worker support must be checked before process creation.');
        }
        $maxAge = $this->config->get('ip-analyzer.max_age_days');
        if (! is_int($maxAge) || $maxAge < 0) {
            throw new \LogicException('Shared database configuration must be validated first.');
        }
        $payload = json_encode([
            'task' => $task->id,
            'database' => $task->database,
            'path' => $task->path,
            'requireEdition' => $task->requireEdition,
            'maxRecords' => $this->options->integer('max_records'),
            'maxAgeDays' => $maxAge,
            'now' => $this->clock->now(),
        ], JSON_THROW_ON_ERROR);
        $encoded = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        $environment = [];
        $inherited = getenv();
        if (is_array($inherited)) {
            $environment = array_fill_keys(array_keys($inherited), false);
            foreach (['PATH', 'PATHEXT', 'SystemRoot', 'WINDIR', 'ComSpec', 'TEMP', 'TMP', 'TMPDIR'] as $name) {
                if (isset($inherited[$name]) && is_string($inherited[$name])) {
                    $environment[$name] = $inherited[$name];
                }
            }
        }
        $process = new Process([$this->php, $this->entry, $this->autoload, $encoded], env: $environment);
        $process->setTimeout($timeout);
        $process->setIdleTimeout(null);

        return new SymfonyWorkerProcess($process);
    }
}
