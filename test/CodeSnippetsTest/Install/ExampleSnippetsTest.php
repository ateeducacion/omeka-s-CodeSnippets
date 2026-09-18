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
use CodeSnippetsTest\Support\FakeLayoutView;
use CodeSnippetsTest\Support\FakeViewEvent;
use CodeSnippetsTest\Support\LoggerSpy;
use CodeSnippetsTest\Support\PdoConnection;
use CodeSnippetsTest\Support\RecordingConnection;
use CodeSnippetsTest\Support\RecordingSharedEventManager;
use PDO;
use PHPUnit\Framework\TestCase;

class ExampleSnippetsTest extends TestCase
{
    public function testInstallSeedsInactiveWordpressStyleExamples(): void
    {
        $connection = new RecordingConnection();
        $services = new ArrayServiceLocator(['Omeka\Connection' => $connection]);

        (new Module())->install($services);

        $this->assertNotEmpty($connection->sql);
        $this->assertStringContainsString('CREATE TABLE `code_snippet`', $connection->sql[0]);
        $this->assertGreaterThanOrEqual(4, count($connection->inserts));

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

        $this->assertContains('Example: lowercase original filenames', $names);
        $this->assertContains('Example: hide the public user bar', $names);
        $this->assertContains('Example: hide the Omeka S version in admin', $names);
        $this->assertContains('Example: add the current year to the site footer', $names);
    }

    public function testPlaygroundBlueprintEnablesTheYearSnippet(): void
    {
        $path = dirname(__DIR__, 3) . '/blueprint.json';
        $blueprint = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($blueprint);
        $this->assertArrayHasKey('phpConstants', $blueprint);
        $this->assertTrue($blueprint['phpConstants']['CODE_SNIPPETS_PLAYGROUND']);
    }

    public function testActivatingTheYearExampleAfterInstallAppendsTheYear(): void
    {
        $connection = $this->sqliteConnection();
        ExampleSnippets::seed($connection);

        $repository = new SnippetRepository($connection);
        $this->assertCount(0, $repository->findActiveOrdered());

        $yearSnippet = $this->findByName(
            $repository,
            'Example: add the current year to the site footer'
        );
        $repository->activate((int) $yearSnippet['id']);

        $shared = new RecordingSharedEventManager();
        $executor = $this->makeExecutor($repository);
        $count = $executor->run(
            new ArrayServiceLocator(['SharedEventManager' => $shared]),
            null,
            [],
            'global_admin',
            'fpm-fcgi'
        );

        $this->assertSame(1, $count);
        $this->assertNotEmpty($shared->attached);

        $view = new FakeLayoutView(false);
        foreach ($shared->attached as $listener) {
            $this->assertSame('view.layout', $listener['event']);
            call_user_func($listener['listener'], new FakeViewEvent($view));
        }

        $this->assertStringContainsString(date('Y'), $view->content);
        $this->assertStringContainsString('code-snippets-year', $view->content);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testPlaygroundInstallRunsTheYearSnippet(): void
    {
        define('CODE_SNIPPETS_PLAYGROUND', true);

        $connection = $this->sqliteConnection();
        ExampleSnippets::seed($connection);

        $repository = new SnippetRepository($connection);
        $active = $repository->findActiveOrdered();
        $this->assertCount(1, $active);
        $this->assertSame(
            'Example: add the current year to the site footer',
            $active[0]['name']
        );

        $shared = new RecordingSharedEventManager();
        $executor = $this->makeExecutor($repository);
        $count = $executor->run(
            new ArrayServiceLocator(['SharedEventManager' => $shared]),
            null,
            [],
            'global_admin',
            'fpm-fcgi'
        );

        $this->assertSame(1, $count);

        $view = new FakeLayoutView(false);
        foreach ($shared->attached as $listener) {
            call_user_func($listener['listener'], new FakeViewEvent($view));
        }
        $this->assertStringContainsString(date('Y'), $view->content);
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
