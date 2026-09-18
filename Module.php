<?php

declare(strict_types=1);

namespace CodeSnippets;

use CodeSnippets\Controller\Admin\SnippetController;
use CodeSnippets\Db\Schema;
use CodeSnippets\Install\ExampleSnippets;
use CodeSnippets\Service\SnippetExecutor;
use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;
use Omeka\Module\AbstractModule;

/**
 * Code Snippets module.
 *
 * Lifecycle, ACL, and listener registration live here. Snippet evaluation does
 * not. Stored PHP is executed only by SnippetExecutor via SnippetEvaluator.
 */
class Module extends AbstractModule
{
    public const RESOURCE_NAME = 'code_snippets';

    /**
     * Privileges checked by Omeka against the controller action name.
     *
     * @var array<int, string>
     */
    public const PRIVILEGES = [
        'index',
        'add',
        'edit',
        'activate',
        'deactivate',
        'delete',
    ];

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    /**
     * Use this module directory rather than OMEKA_PATH/modules/<namespace>.
     * Some layouts (Docker volume mounts) still resolve correctly with __DIR__.
     */
    public function getAutoloaderConfig()
    {
        return [
            'Laminas\Loader\StandardAutoloader' => [
                'namespaces' => [
                    __NAMESPACE__ => __DIR__ . '/src',
                ],
            ],
        ];
    }

    public function onBootstrap(MvcEvent $event): void
    {
        parent::onBootstrap($event);

        $services = $event->getApplication()->getServiceManager();
        $this->registerAcl($services->get('Omeka\Acl'));

        $event->getApplication()->getEventManager()->attach(
            SnippetExecutor::EVENT_NAME,
            [$services->get(SnippetExecutor::class), 'executeFromMvcEvent'],
            SnippetExecutor::PRIORITY
        );
    }

    /**
     * Only global_admin may manage snippets. Other roles are never allowed.
     * Omeka already grants global_admin every privilege; the explicit allow
     * documents the intended matrix and is what tests assert.
     *
     * @param object $acl Omeka\Permissions\Acl
     */
    public function registerAcl($acl): void
    {
        if (method_exists($acl, 'hasResource') && !$acl->hasResource(self::RESOURCE_NAME)) {
            $acl->addResource(self::RESOURCE_NAME);
        }
        if (method_exists($acl, 'hasResource') && !$acl->hasResource(SnippetController::class)) {
            $acl->addResource(SnippetController::class);
        }

        $acl->allow('global_admin', self::RESOURCE_NAME, self::PRIVILEGES);
        $acl->allow('global_admin', SnippetController::class, self::PRIVILEGES);
    }

    public function install(ServiceLocatorInterface $serviceLocator): void
    {
        $this->loadInstallClasses();
        $connection = $serviceLocator->get('Omeka\Connection');
        $connection->exec(Schema::createTableSql());
        ExampleSnippets::seed($connection);
    }

    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $serviceLocator): void
    {
        // Future schema changes belong here, keyed on $oldVersion.
        // Disabling the module must never drop the table; only uninstall() does.
    }

    public function uninstall(ServiceLocatorInterface $serviceLocator): void
    {
        $this->loadSchemaClass();
        $connection = $serviceLocator->get('Omeka\Connection');
        $connection->exec(Schema::dropTableSql());
    }

    /**
     * install()/uninstall() can run before the module autoloader is registered.
     */
    private function loadSchemaClass(): void
    {
        if (!class_exists(Schema::class, false)) {
            require_once __DIR__ . '/src/Db/Schema.php';
        }
    }

    private function loadInstallClasses(): void
    {
        $this->loadSchemaClass();
        if (!class_exists(ExampleSnippets::class, false)) {
            require_once __DIR__ . '/src/Install/ExampleSnippets.php';
        }
    }
}
