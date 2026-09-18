<?php

declare(strict_types=1);

namespace CodeSnippets\Permissions;

use Laminas\Permissions\Acl\Acl;
use Laminas\Permissions\Acl\Assertion\AssertionInterface;
use Laminas\Permissions\Acl\Resource\ResourceInterface;
use Laminas\Permissions\Acl\Role\RoleInterface;

/**
 * Grants snippet management to named individuals rather than to a whole role.
 *
 * Omeka's Acl::userIsAllowed() passes the User entity itself as the role, and
 * Laminas hands an assertion the role object it was originally given, so the
 * concrete user is available here. Anything else — an anonymous request, or a
 * role object that is not a user — is denied.
 */
class AllowedUserAssertion implements AssertionInterface
{
    /** @var array<int, int> */
    private $userIds;

    /**
     * @param array<int, int> $userIds
     */
    public function __construct(array $userIds)
    {
        $this->userIds = $userIds;
    }

    /**
     * @param string|null $privilege
     * @return bool
     */
    public function assert(
        Acl $acl,
        ?RoleInterface $role = null,
        ?ResourceInterface $resource = null,
        $privilege = null
    ) {
        if ($this->userIds === [] || $role === null || !method_exists($role, 'getId')) {
            return false;
        }

        $id = $role->getId();
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            return false;
        }

        return in_array((int) $id, $this->userIds, true);
    }
}
