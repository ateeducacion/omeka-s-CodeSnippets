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

    /**
     * Deny by default, like Omeka's ACL: a role may do something only when an
     * allow() call named that role, resource and privilege.
     *
     * @param mixed $role
     * @param mixed $resource
     * @param mixed $privilege
     */
    public function isAllowed($role, $resource = null, $privilege = null): bool
    {
        foreach ($this->allows as [$allowedRole, $allowedResource, $privileges]) {
            if ($allowedRole !== $role || (string) $allowedResource !== (string) $resource) {
                continue;
            }
            if ($privileges === null) {
                return true;
            }
            if (in_array($privilege, (array) $privileges, true)) {
                return true;
            }
        }
        return false;
    }
}
