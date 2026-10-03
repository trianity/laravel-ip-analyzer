<?php

use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\StreamOutput;

function separatedProgressOutput(): array
{
    $out = fopen('php://memory', 'w+');
    $err = fopen('php://memory', 'w+');
    $output = new class($out, $err) extends ConsoleOutput
    {
        public function __construct($out, $err)
        {
            parent::__construct(decorated: false);
            $this->stdout = $out;
            $this->setErrorOutput(new StreamOutput($err, decorated: false));
        }

        private $stdout;

        protected function doWrite(string $message, bool $newline): void
        {
            fwrite($this->stdout, $message.($newline ? PHP_EOL : ''));
        }
    };

    return [$output, $out, $err];
}
function progressText($stream): string
{
    rewind($stream);

    return stream_get_contents($stream);
}
