<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Acl;

use CodeSnippets\Api\Adapter\SnippetAdapter;
use CodeSnippets\Controller\Admin\SnippetController;
use CodeSnippets\Module;
use CodeSnippetsTest\Support\FakeAcl;
use PHPUnit\Framework\TestCase;

/**
 * Snippet management can be extended to other roles from the module
 * configuration. Such a role gets exactly what global_admin gets: a role that
 * can edit a snippet can run PHP as the web process, so a partial grant would
 * imply a safety that does not exist.
 */
class ManagerRolesTest extends TestCase
{
    private function acl(array $extraRoles = []): FakeAcl
    {
        $acl = new FakeAcl();
        (new Module())->registerAcl($acl, $extraRoles);
        return $acl;
    }

    public function testNoExtraRolesKeepsSnippetsGlobalAdminOnly(): void
    {
        $acl = $this->acl();

        $this->assertTrue($acl->isAllowed('global_admin', SnippetController::class, 'edit'));
        foreach (['site_admin', 'editor', 'reviewer', 'author', 'researcher'] as $role) {
            $this->assertFalse($acl->isAllowed($role, SnippetController::class, 'index'), $role);
        }
    }

    public function testAConfiguredRoleGetsTheSamePrivilegesAsGlobalAdmin(): void
    {
        $acl = $this->acl(['site_admin']);

        foreach (Module::PRIVILEGES as $privilege) {
            $this->assertTrue($acl->isAllowed('site_admin', SnippetController::class, $privilege), $privilege);
            $this->assertTrue($acl->isAllowed('site_admin', Module::RESOURCE_NAME, $privilege), $privilege);
        }
        foreach (Module::API_PRIVILEGES as $operation) {
            $this->assertTrue($acl->isAllowed('site_admin', SnippetAdapter::class, $operation), $operation);
        }
    }

    public function testOtherRolesAreStillDeniedWhenOneIsConfigured(): void
    {
        $acl = $this->acl(['site_admin']);

        $this->assertFalse($acl->isAllowed('editor', SnippetController::class, 'index'));
        $this->assertFalse($acl->isAllowed('author', SnippetAdapter::class, 'search'));
    }

    public function testSeveralRolesCanBeConfigured(): void
    {
        $acl = $this->acl(['site_admin', 'editor']);

        $this->assertTrue($acl->isAllowed('site_admin', SnippetController::class, 'edit'));
        $this->assertTrue($acl->isAllowed('editor', SnippetController::class, 'edit'));
        $this->assertFalse($acl->isAllowed('reviewer', SnippetController::class, 'edit'));
    }

    /**
     * A stale or hand-edited setting must not register a role the ACL does not
     * know, nor duplicate the unconditional global_admin grant.
     */
    public function testUnknownAndMalformedRolesAreIgnored(): void
    {
        $acl = $this->acl(['does_not_exist', '', 42, null, ['nested'], 'site_admin', 'site_admin']);

        $roles = array_values(array_unique(array_map(static function (array $allow) {
            return $allow[0];
        }, $acl->allows)));

        $this->assertSame(['global_admin', 'site_admin'], $roles);
    }

    public function testGlobalAdminIsNotGrantedTwice(): void
    {
        $acl = $this->acl(['global_admin']);

        $globalAdminGrants = array_filter($acl->allows, static function (array $allow) {
            return $allow[0] === 'global_admin' && $allow[1] === SnippetController::class;
        });

        $this->assertCount(1, $globalAdminGrants);
    }
}
