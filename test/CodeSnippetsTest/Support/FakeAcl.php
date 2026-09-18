<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Support;

class FakeAcl
{
    /** @var array<string, bool> */
    public $resources = [];

    /** @var array<int, array{0:mixed,1:mixed,2:mixed}> */
    public $allows = [];

    public function hasResource($resource): bool
    {
        return isset($this->resources[(string) $resource]);
    }

    public function addResource($resource): void
    {
        $this->resources[(string) $resource] = true;
    }

    public function allow($role, $resource = null, $privileges = null): void
    {
        $this->allows[] = [$role, $resource, $privileges];
    }
}
