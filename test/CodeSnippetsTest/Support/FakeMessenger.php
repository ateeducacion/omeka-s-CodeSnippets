<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Support;

class FakeMessenger
{
    /** @var array<int, string> */
    public $success = [];

    public function addSuccess($message): void
    {
        $this->success[] = (string) $message;
    }
}
