<?php

declare(strict_types=1);

namespace CodeSnippets;

use CodeSnippets\Api\Adapter\SnippetAdapter;
use CodeSnippets\Controller\Admin\SnippetController;
use CodeSnippets\Db\Schema;
use CodeSnippets\Permissions\AllowedUserAssertion;
use CodeSnippets\Install\ExampleSnippets;
use CodeSnippets\Service\SnippetExecutor;
use CodeSnippets\Service\PhpValidator;
use CodeSnippets\Service\SnippetRepository;
use CodeSnippets\Service\SnippetService;
use CodeSnippets\Service\SnippetSigner;
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

    /**
     * Individual users allowed to manage snippets, whatever their role, as
     * chosen in the module configuration. Stored as an array of user ids.
     */
    public const MANAGE_USERS_SETTING = 'codesnippets_manage_users';

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
        $this->registerAcl(
            $services->get('Omeka\Acl'),
            $this->managerRoles($services),
            $this->managerUsers($services)
        );

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
    public function registerAcl($acl, array $extraRoles = [], array $extraUsers = []): void
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

        $userIds = self::sanitizeUserIds($extraUsers);
        if ($userIds === []) {
            return;
        }

        // Rule for every role, narrowed to named users by the assertion. A
        // role-specific allow above is matched first, so this only ever widens.
        $assertion = new AllowedUserAssertion($userIds);
        $acl->allow(null, self::RESOURCE_NAME, self::PRIVILEGES, $assertion);
        $acl->allow(null, SnippetController::class, self::PRIVILEGES, $assertion);
        $acl->allow(null, SnippetAdapter::class, self::API_PRIVILEGES, $assertion);
    }

    /**
     * Positive integers only, de-duplicated. A malformed entry is dropped
     * rather than coerced, so a stray value cannot become user 0.
     *
     * @param array<int, mixed> $userIds
     * @return array<int, int>
     */
    public static function sanitizeUserIds(array $userIds): array
    {
        $clean = [];
        foreach ($userIds as $id) {
            if (is_int($id)) {
                $candidate = $id;
            } elseif (is_string($id) && ctype_digit(trim($id))) {
                $candidate = (int) trim($id);
            } else {
                continue;
            }
            if ($candidate > 0) {
                $clean[$candidate] = $candidate;
            }
        }
        return array_values($clean);
    }

    /**
     * Users the operator allowed to manage snippets. Fails closed, like the
     * role list.
     *
     * @param object $services
     * @return array<int, int>
     */
    private function managerUsers($services): array
    {
        try {
            if (method_exists($services, 'has') && !$services->has('Omeka\Settings')) {
                return [];
            }
            $users = $services->get('Omeka\Settings')->get(self::MANAGE_USERS_SETTING, []);
            return is_array($users) ? self::sanitizeUserIds($users) : [];
        } catch (\Throwable $e) {
            return [];
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
            . $this->rolesField($renderer, $settings)
            . $this->usersField($renderer, $settings)
            . $this->integrityField($renderer);
    }

    private function integrityField($renderer): string
    {
        $translate = $renderer->plugin('translate');
        $escape = $renderer->plugin('escapeHtml');
        $state = $this->installationSigner($this->getServiceLocator())->state();
        if ($state === SnippetSigner::ENABLED) {
            $heading = 'Database integrity signing: enabled'; // @translate
            $help = 'Unsigned or modified active snippets are blocked before execution.'; // @translate
            $review = 'Existing snippets must be reviewed and saved again before they can execute.'; // @translate
        } elseif ($state === SnippetSigner::MISCONFIGURED) {
            $heading = 'Database integrity signing: configuration error'; // @translate
            $help = 'Snippets will not execute until the signing key configuration is corrected.'; // @translate
            $review = '';
        } else {
            $heading = 'Database integrity signing: disabled'; // @translate
            $help = 'Optional. Configure code_snippets.signing_key in config/local.config.php.'; // @translate
            $review = '';
        }
        return '<div class="field"><div class="field-meta">' . $escape($translate($heading))
            . '</div><div class="inputs"><p>' . $escape($translate($help)) . '</p>'
            . ($review !== '' ? '<p>' . $escape($translate($review)) . '</p>' : '') . '</div></div>';
    }

    /** Resolve external configuration even before module services are registered at install time. */
    private function installationSigner($services): SnippetSigner
    {
        try {
            return SnippetSigner::fromConfig($services->has('Config') ? $services->get('Config') : []);
        } catch (\Throwable $ignored) {
            return new SnippetSigner(false);
        }
    }

    /**
     * Named users allowed to manage snippets whatever their role. Ids are
     * echoed back with the account they resolve to, so a mistyped number is
     * visible instead of silently granting nobody, or the wrong person.
     *
     * @param object $renderer
     * @param object $settings
     */
    private function usersField($renderer, $settings): string
    {
        $translate = $renderer->plugin('translate');
        $escape = $renderer->plugin('escapeHtml');
        $escapeAttr = $renderer->plugin('escapeHtmlAttr');

        $ids = self::sanitizeUserIds((array) $settings->get(self::MANAGE_USERS_SETTING, []));
        $heading = 'Individual users who may manage snippets'; // @translate
        $help = 'Comma separated user ids. A user id appears in the URL of that user\'s admin page.'; // @translate
        $same = 'The same warning applies: these users can run PHP as the web process.'; // @translate
        $unknown = 'no such user'; // @translate

        $resolved = '';
        $labels = $this->userLabels($ids);
        if ($ids !== []) {
            $items = '';
            foreach ($ids as $id) {
                $items .= '<li>' . $escape((string) $id) . ' &mdash; '
                    . $escape(isset($labels[$id]) ? $labels[$id] : $translate($unknown))
                    . '</li>';
            }
            $resolved = '<ul>' . $items . '</ul>';
        }

        $id = self::MANAGE_USERS_SETTING;

        return '<div class="field">'
            . '<div class="field-meta"><label for="' . $escapeAttr($id) . '">'
            . $escape($translate($heading)) . '</label></div>'
            . '<div class="inputs">'
            . '<input type="text" name="' . $escapeAttr($id) . '" id="' . $escapeAttr($id) . '"'
            . ' value="' . $escapeAttr(implode(', ', $ids)) . '">'
            . $resolved
            . '<p>' . $escape($translate($help)) . '</p>'
            . '<p>' . $escape($translate($same)) . '</p>'
            . '</div></div>';
    }

    /**
     * Resolve user ids to a readable label. Best effort: a lookup failure
     * leaves the id unlabelled rather than blocking the configuration page.
     *
     * @param array<int, int> $ids
     * @return array<int, string>
     */
    private function userLabels(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        try {
            $services = $this->getServiceLocator();
            if (method_exists($services, 'has') && !$services->has('Omeka\ApiManager')) {
                return [];
            }
            $api = $services->get('Omeka\ApiManager');
            $labels = [];
            foreach ($ids as $id) {
                try {
                    $user = $api->read('users', $id)->getContent();
                    $labels[$id] = sprintf('%s <%s>', $user->name(), $user->email());
                } catch (\Throwable $e) {
                    continue;
                }
            }
            return $labels;
        } catch (\Throwable $e) {
            return [];
        }
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

        $users = $controller->params()->fromPost(self::MANAGE_USERS_SETTING, '');
        $settings->set(
            self::MANAGE_USERS_SETTING,
            self::sanitizeUserIds(is_array($users) ? $users : explode(',', (string) $users))
        );

        return true;
    }

    public function install(ServiceLocatorInterface $serviceLocator): void
    {
        $this->loadInstallClasses();
        $connection = $serviceLocator->get('Omeka\Connection');
        $connection->exec(Schema::createTableSql());
        ExampleSnippets::seed($connection, new SnippetService(
            new SnippetRepository($connection),
            new PhpValidator(),
            $this->installationSigner($serviceLocator)
        ));
    }

    public function upgrade($oldVersion, $newVersion, ServiceLocatorInterface $serviceLocator): void
    {
        $this->loadSchemaClass();
        $connection = $serviceLocator->get('Omeka\Connection');
        if (!$this->hasColumn($connection, 'run_scope')) {
            $connection->exec(Schema::addRunScopeColumnSql());
        }
        if (!$this->hasColumn($connection, 'signature')) {
            $connection->exec(Schema::addSignatureColumnSql());
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
        foreach ([
            'Exception/SnippetIntegrityException',
            'Exception/SnippetNotFoundException',
            'Exception/InvalidSyntaxException',
            'Service/SnippetRepositoryInterface',
            'Service/SnippetRepository',
            'Service/ValidationResult',
            'Service/PhpValidator',
            'Service/SnippetScope',
            'Service/SnippetSigner',
            'Service/SnippetService',
        ] as $path) {
            require_once __DIR__ . '/src/' . $path . '.php';
        }
        if (!class_exists(ExampleSnippets::class, false)) {
            require_once __DIR__ . '/src/Install/ExampleSnippets.php';
        }
    }
}
