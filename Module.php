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
    /**
     * Roles allowed to manage snippets besides global_admin, as chosen in the
     * module configuration. Stored as an array of role identifiers.
     */
    public const MANAGE_ROLES_SETTING = 'codesnippets_manage_roles';

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
        $this->registerAcl($services->get('Omeka\Acl'), $this->managerRoles($services));

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
    public function registerAcl($acl, array $extraRoles = []): void
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

        $roles = array_merge(['global_admin'], $this->sanitizeRoles($acl, $extraRoles));
        foreach ($roles as $role) {
            $acl->allow($role, self::RESOURCE_NAME, self::PRIVILEGES);
            $acl->allow($role, SnippetController::class, self::PRIVILEGES);
            $acl->allow($role, SnippetAdapter::class, self::API_PRIVILEGES);
        }
    }

    /**
     * Keep only roles the ACL actually knows. global_admin is dropped because
     * it is granted unconditionally, and an unknown identifier is ignored
     * rather than registered, so a stale setting cannot invent a role.
     *
     * @param object $acl
     * @param array<int, mixed> $roles
     * @return array<int, string>
     */
    private function sanitizeRoles($acl, array $roles): array
    {
        $known = method_exists($acl, 'getRoleLabels') ? array_keys($acl->getRoleLabels()) : null;

        $clean = [];
        foreach ($roles as $role) {
            if (!is_string($role) || $role === '' || $role === 'global_admin') {
                continue;
            }
            if ($known !== null && !in_array($role, $known, true)) {
                continue;
            }
            $clean[$role] = $role;
        }
        return array_values($clean);
    }

    /**
     * Roles the operator allowed to manage snippets. Any failure to read the
     * setting grants nothing: snippet management is global_admin only until
     * the configuration says otherwise.
     *
     * @param object $services
     * @return array<int, string>
     */
    private function managerRoles($services): array
    {
        try {
            if (method_exists($services, 'has') && !$services->has('Omeka\Settings')) {
                return [];
            }
            $roles = $services->get('Omeka\Settings')->get(self::MANAGE_ROLES_SETTING, []);
            return is_array($roles) ? $roles : [];
        } catch (\Throwable $e) {
            return [];
        }
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
            . '</div></div>'
            . $this->rolesField($renderer, $settings);
    }

    /**
     * Roles allowed to manage snippets. Every role listed here gets the same
     * privileges as global_admin, because a role that can edit a snippet can
     * run PHP as the web process and therefore grant itself anything.
     *
     * @param object $renderer
     * @param object $settings
     */
    private function rolesField($renderer, $settings): string
    {
        $labels = $this->roleLabels();
        if ($labels === []) {
            return '';
        }

        $translate = $renderer->plugin('translate');
        $escape = $renderer->plugin('escapeHtml');
        $escapeAttr = $renderer->plugin('escapeHtmlAttr');
        $selected = (array) $settings->get(self::MANAGE_ROLES_SETTING, []);

        $heading = 'Additional roles that may manage snippets'; // @translate
        $warning = 'A role listed here can create and edit PHP that Omeka executes.'; // @translate
        $consequence = 'That is equivalent to granting global administrator.'; // @translate
        $advice = 'Only add roles held by people you would already trust with the server.'; // @translate

        $boxes = '';
        foreach ($labels as $role => $label) {
            $id = self::MANAGE_ROLES_SETTING . '-' . $role;
            $boxes .= '<label for="' . $escapeAttr($id) . '" style="display:block">'
                . '<input type="checkbox" name="' . $escapeAttr(self::MANAGE_ROLES_SETTING) . '[]"'
                . ' id="' . $escapeAttr($id) . '" value="' . $escapeAttr($role) . '"'
                . (in_array($role, $selected, true) ? ' checked="checked"' : '') . '> '
                . $escape($translate($label))
                . '</label>';
        }

        return '<div class="field">'
            . '<div class="field-meta"><label>' . $escape($translate($heading)) . '</label></div>'
            . '<div class="inputs">'
            . $boxes
            . '<p>' . $escape($translate($warning)) . ' ' . $escape($translate($consequence)) . '</p>'
            . '<p>' . $escape($translate($advice)) . '</p>'
            . '</div></div>';
    }

    /**
     * Assignable roles, global_admin excluded because it always has access.
     *
     * @return array<string, string>
     */
    private function roleLabels(): array
    {
        try {
            $services = $this->getServiceLocator();
            if (method_exists($services, 'has') && !$services->has('Omeka\Acl')) {
                return [];
            }
            $acl = $services->get('Omeka\Acl');
            if (!method_exists($acl, 'getRoleLabels')) {
                return [];
            }
            $labels = $acl->getRoleLabels();
            unset($labels['global_admin']);
            return is_array($labels) ? $labels : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param object $controller Laminas\Mvc\Controller\AbstractController
     */
    public function handleConfigForm($controller)
    {
        $settings = $this->getServiceLocator()->get('Omeka\Settings');
        $posted = $controller->params()->fromPost(SnippetAdapter::WRITE_SETTING);
        $settings->set(SnippetAdapter::WRITE_SETTING, !empty($posted));

        $roles = (array) $controller->params()->fromPost(self::MANAGE_ROLES_SETTING, []);
        $allowed = array_keys($this->roleLabels());
        $settings->set(self::MANAGE_ROLES_SETTING, array_values(array_intersect($roles, $allowed)));

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
