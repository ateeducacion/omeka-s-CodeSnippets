<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Install;

use CodeSnippets\Db\Schema;
use CodeSnippets\Install\ExampleSnippets;
use CodeSnippets\Module;
use CodeSnippets\Service\PhpValidator;
use CodeSnippets\Service\SafeMode;
use CodeSnippets\Service\SnippetEvaluator;
use CodeSnippets\Service\SnippetExecutor;
use CodeSnippets\Service\SnippetRepository;
use CodeSnippetsTest\Support\ArrayServiceLocator;
use CodeSnippetsTest\Support\FakeMessenger;
use CodeSnippetsTest\Support\LoggerSpy;
use CodeSnippetsTest\Support\PdoConnection;
use CodeSnippetsTest\Support\RecordingConnection;
use PDO;
use PHPUnit\Framework\TestCase;

class ExampleSnippetsTest extends TestCase
{
    public function testInstallSeedsInactiveExampleSnippets(): void
    {
        $connection = new RecordingConnection();
        $services = new ArrayServiceLocator(['Omeka\Connection' => $connection]);

        (new Module())->install($services);

        $this->assertNotEmpty($connection->sql);
        $this->assertStringContainsString('CREATE TABLE `code_snippet`', $connection->sql[0]);
        $this->assertGreaterThanOrEqual(3, count($connection->inserts));

        $names = [];
        $validator = new PhpValidator();
        foreach ($connection->inserts as $insert) {
            $this->assertSame(Schema::TABLE, $insert[0]);
            $row = $insert[1];
            $this->assertSame(0, $row['active'], $row['name'] . ' must be inactive on a default install');
            $this->assertNotSame('', $row['name']);
            $this->assertNotSame('', $row['code']);
            $this->assertNotSame('', $row['description']);
            $result = $validator->validate($row['code']);
            $this->assertTrue(
                $result->isValid(),
                $row['name'] . ': ' . (string) $result->getMessage()
            );
            $names[] = $row['name'];
        }

        $this->assertContains('Example: log a message', $names);
        $this->assertContains('Example: listen for new items', $names);
        $this->assertContains('Example: add a response header', $names);
        $this->assertContains('Example: confirm snippets run', $names);
    }

    public function testPlaygroundBlueprintEnablesTheConfirmationSnippet(): void
    {
        $path = dirname(__DIR__, 3) . '/blueprint.json';
        $blueprint = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($blueprint);
        $this->assertArrayHasKey('phpConstants', $blueprint);
        $this->assertTrue($blueprint['phpConstants']['CODE_SNIPPETS_PLAYGROUND']);
    }

    public function testActivatingTheLogExampleAfterInstallRunsIt(): void
    {
        $connection = $this->sqliteConnection();
        ExampleSnippets::seed($connection);

        $repository = new SnippetRepository($connection);
        $this->assertCount(0, $repository->findActiveOrdered());

        $logSnippet = $this->findByName($repository, 'Example: log a message');
        $repository->activate((int) $logSnippet['id']);

        $logger = new LoggerSpy();
        $executor = $this->makeExecutor($repository);
        $count = $executor->run(
            new ArrayServiceLocator(['Omeka\Logger' => $logger]),
            null,
            [],
            'global_admin',
            'fpm-fcgi'
        );

        $this->assertSame(1, $count);
        $this->assertContains('Hello from CodeSnippets', $logger->messages);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testPlaygroundInstallRunsTheConfirmationSnippet(): void
    {
        define('CODE_SNIPPETS_PLAYGROUND', true);

        $connection = $this->sqliteConnection();
        ExampleSnippets::seed($connection);

        $repository = new SnippetRepository($connection);
        $active = $repository->findActiveOrdered();
        $this->assertCount(1, $active);
        $this->assertSame('Example: confirm snippets run', $active[0]['name']);

        $logger = new LoggerSpy();
        $messenger = new FakeMessenger();
        $executor = $this->makeExecutor($repository);
        $count = $executor->run(
            new ArrayServiceLocator([
                'Omeka\Logger' => $logger,
                'ControllerPluginManager' => new ArrayServiceLocator([
                    'messenger' => $messenger,
                ]),
            ]),
            null,
            [],
            'global_admin',
            'fpm-fcgi'
        );

        $this->assertSame(1, $count);
        $this->assertContains('Code Snippets ran after install.', $logger->messages);
        $this->assertNotEmpty($messenger->success);
        $this->assertStringContainsString(
            'Code Snippets ran after install.',
            $messenger->success[0]
        );
    }

    private function sqliteConnection(): PdoConnection
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for install smoke tests');
        }
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(Schema::createTableSqliteSql());
        $pdo->exec(Schema::createIndexSqliteSql());
        return new PdoConnection($pdo);
    }

    /**
     * @return array<string, mixed>
     */
    private function findByName(SnippetRepository $repository, string $name): array
    {
        foreach ($repository->findAll() as $snippet) {
            if ($snippet['name'] === $name) {
                return $snippet;
            }
        }
        $this->fail('Snippet not found: ' . $name);
    }

    private function makeExecutor(SnippetRepository $repository): SnippetExecutor
    {
        return new SnippetExecutor(
            $repository,
            new SafeMode(),
            new SnippetEvaluator(),
            new PhpValidator(),
            new LoggerSpy(),
            null
        );
    }
}
