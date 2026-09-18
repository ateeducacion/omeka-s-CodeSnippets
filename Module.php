<?php

declare(strict_types=1);

namespace CodeSnippets;

use CodeSnippets\Api\Adapter\SnippetAdapter;
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

    /**
     * API operations allowed on the adapter. Omeka's API manager checks these
     * against the adapter resource before dispatching. Batch operations are
     * deliberately absent: the adapter does not implement them, and leaving
     * them unlisted keeps them denied.
     *
     * @var array<int, string>
     */
    public const API_PRIVILEGES = [
        'search',
        'read',
        'create',
        'update',
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

        if (method_exists($acl, 'hasResource') && !$acl->hasResource(SnippetAdapter::class)) {
            $acl->addResource(SnippetAdapter::class);
        }

        $acl->allow('global_admin', self::RESOURCE_NAME, self::PRIVILEGES);
        $acl->allow('global_admin', SnippetController::class, self::PRIVILEGES);
        $acl->allow('global_admin', SnippetAdapter::class, self::API_PRIVILEGES);
    }

    /**
     * Single checkbox for the API write gate. Rendered as plain markup rather
     * than a Laminas form: Omeka wraps this in its own <form> and there is one
     * boolean to collect.
     *
     * @param object $renderer Laminas\View\Renderer\PhpRenderer
     */
    public function getConfigForm($renderer)
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $enabled = (bool) $settings->get(SnippetAdapter::WRITE_SETTING, false);
        $translate = $renderer->plugin('translate');
        $escape = $renderer->plugin('escapeHtml');
        $escapeAttr = $renderer->plugin('escapeHtmlAttr');

        $label = 'Allow snippet writes over the REST API'; // @translate
        $warning = 'Disabled by default. An API key could then create and change snippet PHP.'; // @translate
        $note = 'Omeka sends API credentials in the query string, where server logs record them.'; // @translate
        $reads = 'Reading snippets over the API is not affected by this setting.'; // @translate

        $id = $escapeAttr(SnippetAdapter::WRITE_SETTING);

        return '<div class="field">'
            . '<div class="field-meta">'
            . '<label for="' . $id . '">' . $escape($translate($label)) . '</label>'
            . '</div>'
            . '<div class="inputs">'
            . '<input type="checkbox" name="' . $id . '" id="' . $id . '" value="1"'
            . ($enabled ? ' checked="checked"' : '') . '>'
            . '<p>' . $escape($translate($warning)) . ' ' . $escape($translate($note)) . '</p>'
            . '<p>' . $escape($translate($reads)) . '</p>'
            . '</div></div>';
    }

    /**
     * @param object $controller Laminas\Mvc\Controller\AbstractController
     */
    public function handleConfigForm($controller)
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $posted = $controller->params()->fromPost(SnippetAdapter::WRITE_SETTING);
        $settings->set(SnippetAdapter::WRITE_SETTING, !empty($posted));
        return true;
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
        $this->loadSchemaClass();
        $connection = $serviceLocator->get('Omeka\Connection');
        if (!$this->hasColumn($connection, 'run_scope')) {
            $connection->exec(Schema::addRunScopeColumnSql());
        }
    }

    /**
     * @param object $connection Doctrine\DBAL\Connection
     */
    private function hasColumn($connection, string $column): bool
    {
        if (!method_exists($connection, 'getSchemaManager')) {
            return false;
        }
        $columns = $connection->getSchemaManager()->listTableColumns(Schema::TABLE);
        return isset($columns[$column]);
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
