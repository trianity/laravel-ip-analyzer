<?php

declare(strict_types=1);

namespace Trianity\IpAnalyzer\Update\Progress;

final class InterruptHandler
{
    private mixed $previous = null;

    private bool $async = false;

    private bool $installed = false;

    public function install(Progress $progress): void
    {
        if (! function_exists('pcntl_signal') || ! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal_get_handler')) {
            return;
        }
        $this->previous = pcntl_signal_get_handler(SIGINT);
        $this->async = pcntl_async_signals();
        // Set a flag only: asynchronous exceptions could bypass resource ownership transfer.
        pcntl_signal(SIGINT, static function () use ($progress): void {
            $progress->cancel();
        });
        pcntl_async_signals(true);
        $this->installed = true;
    }

    public function restore(): void
    {
        if ($this->installed) {
            pcntl_signal(SIGINT, $this->previous);
            pcntl_async_signals($this->async);
            $this->installed = false;
        }
    }
}
