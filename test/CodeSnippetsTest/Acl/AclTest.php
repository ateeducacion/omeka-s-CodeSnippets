<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Acl;

use CodeSnippets\Controller\Admin\SnippetController;
use CodeSnippets\Module;
use CodeSnippetsTest\Support\FakeAcl;
use PHPUnit\Framework\TestCase;

class AclTest extends TestCase
{
    public function testOnlyGlobalAdminIsGrantedSnippetPrivileges(): void
    {
        $module = new Module();
        $acl = new FakeAcl();
        $module->registerAcl($acl);

        $this->assertNotEmpty($acl->allows);
        $roles = [];
        foreach ($acl->allows as $allow) {
            $roles[] = $allow[0];
            $this->assertContains($allow[1], [Module::RESOURCE_NAME, SnippetController::class]);
            $this->assertSame(Module::PRIVILEGES, $allow[2]);
        }
        $this->assertSame(['global_admin'], array_values(array_unique($roles)));
        $this->assertNotContains('editor', $roles);
        $this->assertNotContains('site_admin', $roles);
        $this->assertNotContains('reviewer', $roles);
        $this->assertNotContains('author', $roles);
        $this->assertNotContains('researcher', $roles);
        $this->assertNotContains(null, $roles);
    }

    public function testPrivilegesCoverManagementActions(): void
    {
        foreach (['index', 'add', 'edit', 'activate', 'deactivate', 'delete'] as $privilege) {
            $this->assertContains($privilege, Module::PRIVILEGES);
        }
    }
}
