<?php

declare(strict_types=1);

namespace CodeSnippets\Install;

use CodeSnippets\Db\Schema;

/**
 * Example snippets inserted on install.
 *
 * They stay inactive on a normal Omeka S install, matching WordPress
 * Code Snippets / WPCode: demos are copied in, but nothing runs until an
 * administrator turns one on. Each example is an Omeka S version of a
 * well-known WordPress snippet (lowercase uploads, hide the admin bar,
 * hide the generator version, current year in the footer).
 *
 * The Omeka S Playground blueprint defines CODE_SNIPPETS_PLAYGROUND so
 * the current-year example is active there and you can see it working.
 */
class ExampleSnippets
{
    public const PLAYGROUND_CONSTANT = 'CODE_SNIPPETS_PLAYGROUND';

    /**
     * @param object $connection Doctrine\DBAL\Connection or compatible test double
     */
    public static function seed($connection): void
    {
        $now = gmdate('Y-m-d H:i:s');
        foreach (self::definitions() as $snippet) {
            $connection->insert(Schema::TABLE, [
                'name' => $snippet['name'],
                'description' => $snippet['description'],
                'code' => $snippet['code'],
                'priority' => $snippet['priority'],
                'active' => !empty($snippet['active']) ? 1 : 0,
                'created' => $now,
                'modified' => $now,
                'last_error_type' => null,
                'last_error_message' => null,
                'last_error_line' => null,
                'last_error_at' => null,
            ]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'name' => 'Example: lowercase original filenames',
                'description' => 'WordPress analog: add_filter("sanitize_file_name", "mb_strtolower"). '
                    . 'Lowercases the original filename stored on uploaded media (o:source). '
                    . 'Inactive by default.',
                'priority' => 10,
                'active' => false,
                'code' => <<<'PHP'
$shared = $services->get('SharedEventManager');
$shared->attach(
    'Omeka\Api\Adapter\MediaAdapter',
    'api.hydrate.pre',
    function ($event) {
        $request = $event->getParam('request');
        if (!is_object($request) || $request->getOperation() !== 'create') {
            return;
        }
        $files = $request->getFileData();
        if (isset($files['file']) && is_array($files['file'])) {
            foreach ($files['file'] as $index => $file) {
                if (!empty($file['name'])) {
                    $files['file'][$index]['name'] = mb_strtolower($file['name']);
                }
            }
            $request->setFileData($files);
        }
        $data = $request->getContent();
        if (!empty($data['o:source']) && is_string($data['o:source'])) {
            $data['o:source'] = mb_strtolower($data['o:source']);
            $request->setContent($data);
        }
    }
);
PHP,
            ],
            [
                'name' => 'Example: hide the public user bar',
                'description' => 'WordPress analog: add_filter("show_admin_bar", "__return_false"). '
                    . 'Hides Omeka’s public user bar (#user-bar) for logged-in visitors. '
                    . 'Inactive by default.',
                'priority' => 10,
                'active' => false,
                'code' => <<<'PHP'
$shared = $services->get('SharedEventManager');
$shared->attach('*', 'view.layout', function ($event) {
    $view = $event->getTarget();
    if (!is_object($view) || !method_exists($view, 'params') || !method_exists($view, 'headStyle')) {
        return;
    }
    $params = $view->params()->fromRoute();
    if (!empty($params['__ADMIN__'])) {
        return;
    }
    $view->headStyle()->appendStyle('#user-bar { display: none !important; }');
});
PHP,
            ],
            [
                'name' => 'Example: hide the Omeka S version in admin',
                'description' => 'WordPress analog: remove the generator / version number. '
                    . 'Hides the version string in the admin footer. Inactive by default.',
                'priority' => 10,
                'active' => false,
                'code' => <<<'PHP'
$shared = $services->get('SharedEventManager');
$shared->attach('*', 'view.layout', function ($event) {
    $view = $event->getTarget();
    if (!is_object($view) || !method_exists($view, 'params') || !method_exists($view, 'headStyle')) {
        return;
    }
    $params = $view->params()->fromRoute();
    if (empty($params['__ADMIN__'])) {
        return;
    }
    $view->headStyle()->appendStyle('.site-version .version-number { display: none; }');
});
PHP,
            ],
            [
                'name' => 'Example: add the current year to the site footer',
                'description' => 'WordPress analog: a [year] / current-year snippet. '
                    . 'Appends © YYYY after the page content (just above the theme footer). '
                    . 'Inactive on a normal install. The Omeka S Playground blueprint activates '
                    . 'it so you can confirm snippets run after install.',
                'priority' => 1,
                'active' => self::isPlayground(),
                'code' => <<<'PHP'
$shared = $services->get('SharedEventManager');
$shared->attach('*', 'view.layout', function ($event) {
    $view = $event->getTarget();
    if (!is_object($view) || !method_exists($view, 'vars')) {
        return;
    }
    $vars = $view->vars();
    $year = date('Y');
    $html = sprintf(
        '<p class="code-snippets-year">&copy; %s</p>',
        htmlspecialchars($year, ENT_QUOTES, 'UTF-8')
    );
    if (is_array($vars) || $vars instanceof \ArrayAccess) {
        $current = isset($vars['content']) ? $vars['content'] : '';
        $vars['content'] = $current . $html;
    }
});
PHP,
            ],
        ];
    }

    public static function isPlayground(): bool
    {
        return defined(self::PLAYGROUND_CONSTANT)
            && constant(self::PLAYGROUND_CONSTANT);
    }
}
