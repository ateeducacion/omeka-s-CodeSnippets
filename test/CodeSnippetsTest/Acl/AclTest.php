<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Acl;

use CodeSnippets\Api\Adapter\SnippetAdapter;
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
        $expectedPrivileges = [
            Module::RESOURCE_NAME => Module::PRIVILEGES,
            SnippetController::class => Module::PRIVILEGES,
            SnippetAdapter::class => Module::API_PRIVILEGES,
        ];

        $roles = [];
        foreach ($acl->allows as $allow) {
            $roles[] = $allow[0];
            $this->assertArrayHasKey($allow[1], $expectedPrivileges);
            $this->assertSame($expectedPrivileges[$allow[1]], $allow[2]);
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
