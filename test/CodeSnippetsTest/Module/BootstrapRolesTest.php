<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Module;

use CodeSnippets\Controller\Admin\SnippetController;
use CodeSnippets\Module;
use CodeSnippets\Service\SnippetEvaluator;
use CodeSnippets\Service\SnippetExecutor;
use CodeSnippetsTest\Support\FakeAcl;
use CodeSnippetsTest\Support\FakeContainer;
use CodeSnippetsTest\Support\InMemorySnippetRepository;
use PHPUnit\Framework\TestCase;

/**
 * The configured roles are read during bootstrap. Reading them must never be
 * able to widen access by accident: anything unexpected leaves snippet
 * management as global_admin only.
 */
class BootstrapRolesTest extends TestCase
{
    /**
     * @param array<string, mixed> $services
     */
    private function boot(array $services): FakeAcl
    {
        $acl = new FakeAcl();
        $container = new FakeContainer(array_merge([
            'Omeka\Acl' => $acl,
            SnippetExecutor::class => new SnippetExecutor(
                new InMemorySnippetRepository(),
                new \CodeSnippets\Service\SafeMode(),
                new SnippetEvaluator(),
                new \CodeSnippets\Service\PhpValidator()
            ),
        ], $services));

        $events = new class {
            public function attach($event, $callback, $priority = 1)
            {
            }
        };
        $application = new class ($container, $events) {
            /** @var object */
            private $services;
            /** @var object */
            private $events;

            public function __construct($services, $events)
            {
                $this->services = $services;
                $this->events = $events;
            }

            public function getServiceManager()
            {
                return $this->services;
            }

            public function getEventManager()
            {
                return $this->events;
            }
        };

        $event = new \Laminas\Mvc\MvcEvent();
        $event->application = $application;
        (new Module())->onBootstrap($event);

        return $acl;
    }

    /**
     * @param mixed $value
     * @return object
     */
    private function settings($value)
    {
        return new class ($value) {
            /** @var mixed */
            private $value;

            public function __construct($value)
            {
                $this->value = $value;
            }

            public function get($key, $default = null)
            {
                return $key === Module::MANAGE_ROLES_SETTING ? $this->value : $default;
            }
        };
    }

    public function testConfiguredRolesAreGrantedAtBootstrap(): void
    {
        $acl = $this->boot(['Omeka\Settings' => $this->settings(['site_admin'])]);

        $this->assertTrue($acl->isAllowed('site_admin', SnippetController::class, 'edit'));
    }

    public function testWithoutTheSettingsServiceOnlyGlobalAdminIsGranted(): void
    {
        $acl = $this->boot([]);

        $this->assertTrue($acl->isAllowed('global_admin', SnippetController::class, 'edit'));
        $this->assertFalse($acl->isAllowed('site_admin', SnippetController::class, 'edit'));
    }

    public function testAnUnsetSettingGrantsNothingExtra(): void
    {
        $acl = $this->boot(['Omeka\Settings' => $this->settings(null)]);

        $this->assertFalse($acl->isAllowed('site_admin', SnippetController::class, 'edit'));
    }

    public function testANonArraySettingGrantsNothingExtra(): void
    {
        $acl = $this->boot(['Omeka\Settings' => $this->settings('site_admin')]);

        $this->assertFalse($acl->isAllowed('site_admin', SnippetController::class, 'edit'));
    }

    public function testAFailingSettingsServiceGrantsNothingExtra(): void
    {
        $throwing = new class {
            public function get($key, $default = null)
            {
                throw new \RuntimeException('settings unavailable');
            }
        };

        $acl = $this->boot(['Omeka\Settings' => $throwing]);

        $this->assertTrue($acl->isAllowed('global_admin', SnippetController::class, 'edit'));
        $this->assertFalse($acl->isAllowed('site_admin', SnippetController::class, 'edit'));
    }
}
