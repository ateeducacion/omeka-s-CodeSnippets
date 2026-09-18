<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Module;

use CodeSnippets\Api\Adapter\SnippetAdapter;
use CodeSnippets\Module;
use CodeSnippetsTest\Support\ArrayServiceLocator;
use PHPUnit\Framework\TestCase;

/**
 * The module configuration exposes exactly one switch: whether the REST API
 * may write snippets. Reads are never affected by it.
 */
class ConfigFormTest extends TestCase
{
    /**
     * @return object
     */
    private function settings(array $stored = [])
    {
        return new class ($stored) {
            /** @var array<string, mixed> */
            public $stored;

            public function __construct(array $stored)
            {
                $this->stored = $stored;
            }

            public function get($key, $default = null)
            {
                return array_key_exists($key, $this->stored) ? $this->stored[$key] : $default;
            }

            public function set($key, $value): void
            {
                $this->stored[$key] = $value;
            }
        };
    }

    /**
     * @return object
     */
    private function renderer()
    {
        return new class {
            public function plugin($name)
            {
                if ($name === 'translate') {
                    return static function ($value) {
                        return $value;
                    };
                }
                return static function ($value) {
                    return htmlspecialchars((string) $value, ENT_QUOTES);
                };
            }
        };
    }

    /**
     * @param mixed $posted
     * @return object
     */
    private function controller($posted, array $extra = [])
    {
        return new class ($posted, $extra) {
            /** @var mixed */
            private $posted;

            /** @var array<string, mixed> */
            private $extra;

            public function __construct($posted, array $extra)
            {
                $this->posted = $posted;
                $this->extra = $extra;
            }

            public function params()
            {
                return new class ($this->posted, $this->extra) {
                    /** @var mixed */
                    private $posted;

                    /** @var array<string, mixed> */
                    private $extra;

                    public function __construct($posted, array $extra)
                    {
                        $this->posted = $posted;
                        $this->extra = $extra;
                    }

                    public function fromPost($name = null, $default = null)
                    {
                        if ($name !== null && array_key_exists($name, $this->extra)) {
                            return $this->extra[$name];
                        }
                        if ($name === Module::MANAGE_ROLES_SETTING) {
                            return $default;
                        }
                        return $this->posted;
                    }
                };
            }
        };
    }

    private function module($settings): Module
    {
        $module = new Module();
        $module->setServiceLocator(new ArrayServiceLocator([
            'Omeka\Settings' => $settings,
            'Omeka\Acl' => new \CodeSnippetsTest\Support\FakeAcl(),
        ]));
        return $module;
    }

    public function testFormRendersUncheckedByDefault(): void
    {
        $html = $this->module($this->settings())->getConfigForm($this->renderer());

        $this->assertStringContainsString(SnippetAdapter::WRITE_SETTING, $html);
        $this->assertStringNotContainsString('checked="checked"', $html);
    }

    public function testFormRendersCheckedWhenWritesAreEnabled(): void
    {
        $settings = $this->settings([SnippetAdapter::WRITE_SETTING => true]);

        $html = $this->module($settings)->getConfigForm($this->renderer());

        $this->assertStringContainsString('checked="checked"', $html);
    }

    public function testSubmittingTheCheckboxEnablesWrites(): void
    {
        $settings = $this->settings();

        $result = $this->module($settings)->handleConfigForm($this->controller('1'));

        $this->assertTrue($result);
        $this->assertTrue($settings->stored[SnippetAdapter::WRITE_SETTING]);
    }

    public function testOmittingTheCheckboxDisablesWrites(): void
    {
        $settings = $this->settings([SnippetAdapter::WRITE_SETTING => true]);

        $this->module($settings)->handleConfigForm($this->controller(null));

        $this->assertFalse($settings->stored[SnippetAdapter::WRITE_SETTING]);
    }

    public function testFormListsAssignableRolesWithoutGlobalAdmin(): void
    {
        $html = $this->module($this->settings())->getConfigForm($this->renderer());

        $this->assertStringContainsString('value="site_admin"', $html);
        $this->assertStringContainsString('value="editor"', $html);
        $this->assertStringNotContainsString('value="global_admin"', $html);
    }

    public function testConfiguredRolesRenderChecked(): void
    {
        $settings = $this->settings([Module::MANAGE_ROLES_SETTING => ['editor']]);

        $html = $this->module($settings)->getConfigForm($this->renderer());

        $this->assertStringContainsString('value="editor" checked="checked"', $html);
        $this->assertStringNotContainsString('value="site_admin" checked="checked"', $html);
    }

    public function testSubmittingRolesStoresThem(): void
    {
        $settings = $this->settings();

        $this->module($settings)->handleConfigForm(
            $this->controller(null, [Module::MANAGE_ROLES_SETTING => ['site_admin', 'editor']])
        );

        $this->assertSame(['site_admin', 'editor'], $settings->stored[Module::MANAGE_ROLES_SETTING]);
    }

    /**
     * The form must not be able to persist a role the ACL does not know, nor
     * global_admin, which is granted unconditionally.
     */
    public function testUnknownRolesAreNotStored(): void
    {
        $settings = $this->settings();

        $this->module($settings)->handleConfigForm(
            $this->controller(null, [Module::MANAGE_ROLES_SETTING => ['site_admin', 'global_admin', 'wat']])
        );

        $this->assertSame(['site_admin'], $settings->stored[Module::MANAGE_ROLES_SETTING]);
    }

    public function testClearingEveryCheckboxRemovesAllRoles(): void
    {
        $settings = $this->settings([Module::MANAGE_ROLES_SETTING => ['site_admin']]);

        $this->module($settings)->handleConfigForm($this->controller(null));

        $this->assertSame([], $settings->stored[Module::MANAGE_ROLES_SETTING]);
    }

    /**
     * Without the ACL service there is no trustworthy role list, so the module
     * offers no checkboxes rather than guessing role identifiers.
     */
    public function testRoleCheckboxesAreOmittedWhenTheAclIsUnavailable(): void
    {
        $module = new Module();
        $module->setServiceLocator(new ArrayServiceLocator(['Omeka\Settings' => $this->settings()]));

        $html = $module->getConfigForm($this->renderer());

        $this->assertStringContainsString(SnippetAdapter::WRITE_SETTING, $html);
        $this->assertStringNotContainsString('value="site_admin"', $html);
    }

    public function testRolesCannotBeStoredWhenTheAclIsUnavailable(): void
    {
        $settings = $this->settings();
        $module = new Module();
        $module->setServiceLocator(new ArrayServiceLocator(['Omeka\Settings' => $settings]));

        $module->handleConfigForm(
            $this->controller(null, [Module::MANAGE_ROLES_SETTING => ['site_admin']])
        );

        $this->assertSame([], $settings->stored[Module::MANAGE_ROLES_SETTING]);
    }

    /**
     * An ACL that cannot list roles, or one that fails, is treated the same as
     * having none: no checkboxes and nothing storable.
     */
    public function testRoleCheckboxesAreOmittedWhenTheAclCannotListRoles(): void
    {
        $aclWithoutLabels = new class {
            public function hasResource($resource): bool
            {
                return false;
            }

            public function addResource($resource): void
            {
            }

            public function allow($role, $resource = null, $privileges = null): void
            {
            }
        };
        $module = new Module();
        $module->setServiceLocator(new ArrayServiceLocator([
            'Omeka\Settings' => $this->settings(),
            'Omeka\Acl' => $aclWithoutLabels,
        ]));

        $this->assertStringNotContainsString('value="site_admin"', $module->getConfigForm($this->renderer()));
    }

    public function testRoleCheckboxesAreOmittedWhenTheAclThrows(): void
    {
        $throwing = new class {
            public function getRoleLabels(): array
            {
                throw new \RuntimeException('acl unavailable');
            }
        };
        $module = new Module();
        $module->setServiceLocator(new ArrayServiceLocator([
            'Omeka\Settings' => $this->settings(),
            'Omeka\Acl' => $throwing,
        ]));

        $this->assertStringNotContainsString('value="site_admin"', $module->getConfigForm($this->renderer()));
    }
}
