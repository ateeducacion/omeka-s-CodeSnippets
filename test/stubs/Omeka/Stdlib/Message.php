<?php

namespace Omeka\Stdlib;

class Message
{
    /** @var array<int, mixed> */
    private $args;

    public function __construct($message)
    {
        $this->args = func_get_args();
    }

    public function __toString()
    {
        if (count($this->args) === 1) {
            return (string) $this->args[0];
        }
        return vsprintf((string) $this->args[0], array_slice($this->args, 1));
    }
}
