<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update;

use Trianity\IpAnalyzer\Update\Progress\Progress;

class UpdateStorage
{
    public function __construct(private readonly Progress $progress = new Progress) {}

    public function directory(string $target): string
    {
        return dirname($target).'/.ip-analyzer-'.hash('sha256', $target);
    }

    /** @return resource|null */
    public function lock(string $target, bool $check, UpdateOptions $options)
    {
        $directory = $this->directory($target);
        if ($check && ! file_exists($directory.'/update.lock')) {
            return null;
        }
        if (! $check) {
            if (is_link($directory)) {
                throw new UpdateFailure('unsafe_storage');
            }
            if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new UpdateFailure('disk_error');
            }
            if (! @chmod($directory, 0700)) {
                throw new UpdateFailure('disk_error');
            }
        }
        if (is_link($directory) || is_link($directory.'/update.lock')) {
            throw new UpdateFailure('unsafe_storage');
        }
        $lock = @fopen($directory.'/update.lock', $check ? 'rb' : 'c+b');
        if ($lock === false) {
            throw new UpdateFailure('disk_error');
        }
        if (! $check) {
            @chmod($directory.'/update.lock', 0600);
        }
        $deadline = hrtime(true) + $options->integer('lock_timeout') * 1_000_000_000;
        $acquired = false;
        try {
            do {
                $this->progress->checkpoint();
                if (flock($lock, LOCK_EX | LOCK_NB)) {
                    $acquired = true;

                    return $lock;
                }
                if (hrtime(true) >= $deadline) {
                    break;
                }
                $this->progress->advance(0);
                usleep(50000);
            } while (true);
            throw new UpdateFailure('busy');
        } finally {
            if (! $acquired) {
                fclose($lock);
            }
        }
    }

    /** @param resource|null $lock */
    public function unlock($lock): void
    {
        if (is_resource($lock)) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string, mixed> */
    public function state(string $target): array
    {
        $path = $this->directory($target).'/state.json';
        if (is_link($path) || ! is_file($path) || filesize($path) > 65536) {
            return [];
        }
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new UpdateFailure('disk_error');
        }
        $state = json_decode($data, true);

        return is_array($state) ? $state : [];
    }

    /** @param array<string, mixed> $state */
    public function saveState(string $target, array $state): void
    {
        $this->atomic($this->directory($target).'/state.json', json_encode($state, JSON_THROW_ON_ERROR));
    }

    public function stage(string $target, UpdateOptions $options): string
    {
        $directory = $this->directory($target);
        // Called under the target lock only. Never traverse another target's staging.
        foreach (glob($directory.'/stage-*') ?: [] as $old) {
            if (preg_match('/^stage-[a-f0-9]{32}$/D', basename($old)) && ! is_link($old)
                && is_dir($old) && filemtime($old) < time() - $options->integer('cleanup_age')) {
                $this->cleanup($old);
            }
        }
        foreach (glob($directory.'/.state-*') ?: [] as $old) {
            if (preg_match('/^\.state-[a-f0-9]{32}$/D', basename($old)) && ! is_link($old)
                && is_file($old) && filemtime($old) < time() - $options->integer('cleanup_age')) {
                @unlink($old);
            }
        }
        $stage = $directory.'/stage-'.bin2hex(random_bytes(16));
        if (! @mkdir($stage, 0700)) {
            throw new UpdateFailure('disk_error');
        }

        return $stage;
    }

    public function install(string $candidate, string $target): void
    {
        if (is_link($target) || ! @chmod($candidate, 0600) || ! @rename($candidate, $target)) {
            throw new UpdateFailure('install_failed');
        }
        clearstatcache(true, $target);
    }

    /** @param array<string, string> $notices */
    public function saveNotices(string $target, array $notices): void
    {
        $directory = $this->directory($target).'/notices';
        if (is_link($directory)) {
            throw new UpdateFailure('unsafe_storage');
        }
        if (! is_dir($directory) && ! @mkdir($directory, 0700)) {
            throw new UpdateFailure('disk_error');
        }
        foreach ($notices as $name => $path) {
            if (! preg_match('/^(LICENSE|COPYRIGHT|README)(?:\.[A-Za-z0-9_-]+)?$/D', $name)
                || ! @chmod($path, 0600) || ! @rename($path, $directory.'/'.$name)) {
                throw new UpdateFailure('notice_write_failed');
            }
        }
    }

    public function cleanup(string $stage): void
    {
        if (! is_dir($stage) || is_link($stage)) {
            return;
        }
        foreach (new \FilesystemIterator($stage) as $file) {
            // Extractor creates only flat regular files. Do not follow unexpected directories.
            if (! $file->isDir() || $file->isLink()) {
                @unlink($file->getPathname());
            }
        }
        @rmdir($stage);
    }

    private function atomic(string $path, string $contents): void
    {
        $temporary = dirname($path).'/.state-'.bin2hex(random_bytes(16));
        $file = @fopen($temporary, 'xb');
        if ($file === false) {
            throw new UpdateFailure('state_write_failed');
        }
        try {
            if (! @chmod($temporary, 0600) || @fwrite($file, $contents) !== strlen($contents) || ! fflush($file) || ! fsync($file)) {
                throw new UpdateFailure('state_write_failed');
            }
            fclose($file);
            $file = null;
            if (is_link($path) || ! @rename($temporary, $path)) {
                throw new UpdateFailure('state_write_failed');
            }
        } finally {
            if (is_resource($file)) {
                fclose($file);
            }
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
