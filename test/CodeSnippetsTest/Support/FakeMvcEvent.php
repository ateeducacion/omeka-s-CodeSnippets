<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Support;

class FakeMvcEvent
{
    /** @var mixed */
    private $admin;

    public function __construct(bool $isAdmin)
    {
        $this->admin = $isAdmin ? true : null;
    }

    public function getRouteMatch(): self
    {
        return $this;
    }

    public function getParam($name, $default = null)
    {
        if ($name === '__ADMIN__') {
            return $this->admin;
        }
        return $default;
    }
}
