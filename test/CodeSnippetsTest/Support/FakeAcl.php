<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Support;

class FakeAcl
{
    /** @var array<string, bool> */
    public $resources = [];

    /** @var array<int, array{0:mixed,1:mixed,2:mixed}> */
    public $allows = [];

    /** @var array<string, string> */
    public $roleLabels = [
        'global_admin' => 'Global Administrator',
        'site_admin' => 'Supervisor',
        'editor' => 'Editor',
        'reviewer' => 'Reviewer',
        'author' => 'Author',
        'researcher' => 'Researcher',
    ];

    /**
     * @return array<string, string>
     */
    public function getRoleLabels(): array
    {
        return $this->roleLabels;
    }

    public function hasResource($resource): bool
    {
        return isset($this->resources[(string) $resource]);
    }

    public function addResource($resource): void
    {
        $this->resources[(string) $resource] = true;
    }

    public function allow($role, $resource = null, $privileges = null, $assert = null): void
    {
        $this->allows[] = [$role, $resource, $privileges, $assert];
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
        $roleId = is_object($role) && method_exists($role, 'getRoleId') ? $role->getRoleId() : $role;

        foreach ($this->allows as $allow) {
            [$allowedRole, $allowedResource, $privileges] = $allow;
            $assert = $allow[3] ?? null;

            if ((string) $allowedResource !== (string) $resource) {
                continue;
            }
            // A null role in a rule means "every role", as in Laminas.
            if ($allowedRole !== null && $allowedRole !== $roleId) {
                continue;
            }
            if ($privileges !== null && !in_array($privilege, (array) $privileges, true)) {
                continue;
            }
            if ($assert !== null) {
                $assertedRole = is_object($role) ? $role : null;
                if (!$assert->assert(new \Laminas\Permissions\Acl\Acl(), $assertedRole, null, $privilege)) {
                    continue;
                }
            }
            return true;
        }
        return false;
    }
}
