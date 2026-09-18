<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Acl;

use CodeSnippets\Api\Adapter\SnippetAdapter;
use CodeSnippets\Controller\Admin\SnippetController;
use CodeSnippets\Module;
use CodeSnippets\Permissions\AllowedUserAssertion;
use CodeSnippetsTest\Support\FakeAcl;
use Laminas\Permissions\Acl\Acl;
use PHPUnit\Framework\TestCase;

/**
 * Snippet management can be granted to one named person without promoting
 * their whole role. Omeka passes the User entity as the ACL role, so the
 * assertion can tell one user of a role from another.
 */
class ManagerUsersTest extends TestCase
{
    /**
     * Stands in for Omeka\Entity\User, which is a Laminas ACL role.
     */
    private function user(int $id, string $role)
    {
        return new class ($id, $role) implements \Laminas\Permissions\Acl\Role\RoleInterface {
            /** @var int */
            private $id;
            /** @var string */
            private $role;

            public function __construct(int $id, string $role)
            {
                $this->id = $id;
                $this->role = $role;
            }

            public function getId()
            {
                return $this->id;
            }

            public function getRoleId()
            {
                return $this->role;
            }
        };
    }

    private function acl(array $users): FakeAcl
    {
        $acl = new FakeAcl();
        (new Module())->registerAcl($acl, [], $users);
        return $acl;
    }

    public function testANamedUserMayManageSnippets(): void
    {
        $acl = $this->acl([42]);

        $this->assertTrue($acl->isAllowed($this->user(42, 'editor'), SnippetController::class, 'edit'));
        $this->assertTrue($acl->isAllowed($this->user(42, 'editor'), Module::RESOURCE_NAME, 'add'));
        $this->assertTrue($acl->isAllowed($this->user(42, 'editor'), SnippetAdapter::class, 'create'));
    }

    /**
     * The point of the feature: another account with the same role stays out.
     */
    public function testAnotherUserOfTheSameRoleIsStillDenied(): void
    {
        $acl = $this->acl([42]);

        $this->assertFalse($acl->isAllowed($this->user(43, 'editor'), SnippetController::class, 'edit'));
        $this->assertFalse($acl->isAllowed($this->user(43, 'editor'), SnippetAdapter::class, 'search'));
    }

    public function testSeveralUsersCanBeNamed(): void
    {
        $acl = $this->acl([7, 42]);

        $this->assertTrue($acl->isAllowed($this->user(7, 'author'), SnippetController::class, 'edit'));
        $this->assertTrue($acl->isAllowed($this->user(42, 'researcher'), SnippetController::class, 'edit'));
        $this->assertFalse($acl->isAllowed($this->user(8, 'author'), SnippetController::class, 'edit'));
    }

    public function testGlobalAdminIsUnaffectedByTheUserList(): void
    {
        $acl = $this->acl([42]);

        $this->assertTrue($acl->isAllowed($this->user(1, 'global_admin'), SnippetController::class, 'delete'));
    }

    public function testNoNamedUsersGrantsNothingExtra(): void
    {
        $acl = $this->acl([]);

        $this->assertFalse($acl->isAllowed($this->user(42, 'editor'), SnippetController::class, 'index'));
    }

    public function testAnonymousRequestsAreDenied(): void
    {
        $acl = $this->acl([42]);

        $this->assertFalse($acl->isAllowed(null, SnippetController::class, 'index'));
    }

    public function testPrivilegesOutsideTheListAreStillDenied(): void
    {
        $acl = $this->acl([42]);

        $this->assertFalse($acl->isAllowed($this->user(42, 'editor'), SnippetAdapter::class, 'batch_delete'));
    }

    /**
     * @dataProvider malformedIds
     * @param mixed $value
     */
    public function testMalformedIdsNeverGrantAccess($value): void
    {
        $acl = $this->acl([$value]);

        $this->assertFalse($acl->isAllowed($this->user(0, 'editor'), SnippetController::class, 'index'));
        $this->assertFalse($acl->isAllowed($this->user(42, 'editor'), SnippetController::class, 'index'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function malformedIds(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'empty string' => [''],
            'non numeric' => ['abc'],
            'null' => [null],
            'array' => [[42]],
        ];
    }

    public function testNumericStringIdsAreAccepted(): void
    {
        $acl = $this->acl(['42']);

        $this->assertTrue($acl->isAllowed($this->user(42, 'editor'), SnippetController::class, 'index'));
    }

    public function testAssertionRejectsARoleThatIsNotAUser(): void
    {
        $assertion = new AllowedUserAssertion([42]);
        $plainRole = new class implements \Laminas\Permissions\Acl\Role\RoleInterface {
            public function getRoleId()
            {
                return 'editor';
            }
        };

        $this->assertFalse($assertion->assert(new Acl(), $plainRole, null, 'edit'));
        $this->assertFalse($assertion->assert(new Acl(), null, null, 'edit'));
    }

    public function testSanitizeUserIdsDeduplicatesAndDropsJunk(): void
    {
        $this->assertSame([42, 7], Module::sanitizeUserIds([42, '42', 7, 0, -3, 'x', null, [1]]));
    }
}
