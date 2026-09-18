<?php

declare(strict_types=1);

namespace CodeSnippets\Install;

use CodeSnippets\Db\Schema;

/**
 * Example snippets inserted on install.
 *
 * They stay inactive on a normal Omeka S install, matching the WordPress
 * Code Snippets habit of shipping demos that do not run until an
 * administrator turns them on. The Omeka S Playground blueprint defines
 * CODE_SNIPPETS_PLAYGROUND so the confirmation example is active there.
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
                'name' => 'Example: log a message',
                'description' => 'Writes one line through Omeka’s logger. Activate it, then check the logs. '
                    . 'Inactive by default.',
                'priority' => 10,
                'active' => false,
                'code' => <<<'PHP'
$logger = $services->get('Omeka\Logger');
$logger->info('Hello from CodeSnippets');
PHP,
            ],
            [
                'name' => 'Example: listen for new items',
                'description' => 'Attaches to api.create.post on items and logs the new item id. '
                    . 'Inactive by default.',
                'priority' => 10,
                'active' => false,
                'code' => <<<'PHP'
$sharedEventManager = $services->get('SharedEventManager');
$sharedEventManager->attach(
    'Omeka\Api\Adapter\ItemAdapter',
    'api.create.post',
    function ($event) use ($services) {
        $logger = $services->get('Omeka\Logger');
        $response = $event->getParam('response');
        $resource = $response ? $response->getContent() : null;
        $id = is_object($resource) && method_exists($resource, 'getId')
            ? $resource->getId()
            : '?';
        $logger->info(sprintf('Code Snippets example: item #%s was created.', $id));
    }
);
PHP,
            ],
            [
                'name' => 'Example: add a response header',
                'description' => 'Adds X-Code-Snippets-Example: 1 on EVENT_FINISH. Inactive by default.',
                'priority' => 10,
                'active' => false,
                'code' => <<<'PHP'
$event->getApplication()->getEventManager()->attach(
    'finish',
    function ($e) {
        $e->getResponse()->getHeaders()->addHeaderLine('X-Code-Snippets-Example', '1');
    }
);
PHP,
            ],
            [
                'name' => 'Example: confirm snippets run',
                'description' => 'Shows a success message in admin and writes a log line. Left inactive on a '
                    . 'normal install. The Omeka S Playground blueprint activates it so you can confirm '
                    . 'the module ran after install.',
                'priority' => 1,
                'active' => self::isPlayground(),
                'code' => <<<'PHP'
$services->get('Omeka\Logger')->info('Code Snippets ran after install.');
$services->get('ControllerPluginManager')
    ->get('messenger')
    ->addSuccess(
        'Code Snippets ran after install. This example is active in the playground so you can confirm the module works.'
    );
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
