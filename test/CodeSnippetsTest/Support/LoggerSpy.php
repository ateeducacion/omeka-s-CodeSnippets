<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Support;

class LoggerSpy
{
    /** @var array<int, string> */
    public $messages = [];

    public function err($message): void
    {
        $this->messages[] = (string) $message;
    }
}
