<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Support;

class FakeViewEvent
{
    /** @var mixed */
    private $target;

    public function __construct($target)
    {
        $this->target = $target;
    }

    public function getTarget()
    {
        return $this->target;
    }
}
