<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Module;

use CodeSnippets\Db\Schema;
use CodeSnippets\Module;
use CodeSnippets\Service\SnippetEvaluator;
use CodeSnippets\Service\SnippetExecutor;
use PHPUnit\Framework\TestCase;

class LifecycleTest extends TestCase
{
    public function testExecutionListensOnRouteAfterCoreListeners(): void
    {
        $this->assertSame('route', SnippetExecutor::EVENT_NAME);
        $this->assertSame(\Laminas\Mvc\MvcEvent::EVENT_ROUTE, SnippetExecutor::EVENT_NAME);
        $this->assertSame(-10, SnippetExecutor::PRIORITY);
        $this->assertLessThan(1, SnippetExecutor::PRIORITY);
    }

    public function testModuleWiresExecutorWithoutEvaluatingPhp(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/Module.php');
        $this->assertNotFalse($source);
        $this->assertTrue(strpos($source, 'executeFromMvcEvent') !== false);
        $this->assertTrue(strpos($source, 'SnippetExecutor::EVENT_NAME') !== false);
        $this->assertTrue(strpos($source, 'SnippetExecutor::PRIORITY') !== false);
        $this->assertTrue(strpos($source, 'eval(') === false);
    }

    public function testEvalIsIsolatedInSnippetEvaluator(): void
    {
        $root = dirname(__DIR__, 3);
        $hits = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src'));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $contents = file_get_contents($file->getPathname());
            if ($contents !== false && strpos($contents, 'eval(') !== false) {
                $hits[] = $file->getFilename();
            }
        }
        $module = file_get_contents($root . '/Module.php');
        $this->assertTrue(strpos($module, 'eval(') === false);
        $this->assertSame(['SnippetEvaluator.php'], $hits);
    }

    public function testInstallCreatesDedicatedTable(): void
    {
        $this->assertStringContainsString('CREATE TABLE `code_snippet`', Schema::createTableSql());
        $this->assertStringContainsString('DROP TABLE IF EXISTS `code_snippet`', Schema::dropTableSql());
    }

    public function testEvaluatorDoesNotLeakSourceVariable(): void
    {
        $evaluator = new SnippetEvaluator();
        $GLOBALS['code_snippets_eval_keys'] = null;
        $evaluator->evaluate(
            '$GLOBALS["code_snippets_eval_keys"] = array_keys(get_defined_vars());',
            'SERVICES',
            'EVENT'
        );
        $keys = $GLOBALS['code_snippets_eval_keys'];
        sort($keys);
        $this->assertSame(['event', 'services'], $keys);
    }

    public function testModuleGetConfigReturnsArray(): void
    {
        $module = new Module();
        $config = $module->getConfig();
        $this->assertIsArray($config);
        $this->assertArrayHasKey('router', $config);
        $this->assertArrayHasKey('navigation', $config);
        $this->assertArrayHasKey('controllers', $config);
        $this->assertArrayHasKey('translator', $config);
    }

    public function testAutoloaderUsesModuleDirectory(): void
    {
        $module = new Module();
        $config = $module->getAutoloaderConfig();
        $path = $config['Laminas\Loader\StandardAutoloader']['namespaces']['CodeSnippets'];
        $this->assertSame(
            realpath(dirname(__DIR__, 3) . '/src'),
            realpath($path)
        );
    }

    public function testInstallAndUninstallUseConnection(): void
    {
        $connection = new class {
            /** @var array<int, string> */
            public $sql = [];

            public function exec($sql)
            {
                $this->sql[] = (string) $sql;
            }
        };
        $locator = $this->createMock(\Laminas\ServiceManager\ServiceLocatorInterface::class);
        $locator->method('get')->willReturn($connection);

        $module = new Module();
        $module->install($locator);
        $module->upgrade('0.0.0', '0.1.0', $locator);
        $module->uninstall($locator);

        $this->assertCount(2, $connection->sql);
        $this->assertStringContainsString('CREATE TABLE', $connection->sql[0]);
        $this->assertStringContainsString('DROP TABLE', $connection->sql[1]);
    }

    public function testOnBootstrapRegistersAclAndExecutor(): void
    {
        $acl = new \CodeSnippetsTest\Support\FakeAcl();
        $executor = new SnippetExecutor(
            new \CodeSnippetsTest\Support\InMemorySnippetRepository(),
            new \CodeSnippets\Service\SafeMode(),
            new SnippetEvaluator(),
            new \CodeSnippets\Service\PhpValidator()
        );
        $events = new class {
            /** @var array<int, array{0:mixed,1:mixed,2:mixed}> */
            public $attached = [];

            public function attach($event, $callback, $priority = 1)
            {
                $this->attached[] = [$event, $callback, $priority];
            }
        };
        $services = new \CodeSnippetsTest\Support\FakeContainer([
            'Omeka\Acl' => $acl,
            SnippetExecutor::class => $executor,
        ]);
        $application = new class ($services, $events) {
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

        $module = new Module();
        $module->onBootstrap($event);

        $this->assertNotEmpty($acl->allows);
        $this->assertCount(1, $events->attached);
        $this->assertSame(SnippetExecutor::EVENT_NAME, $events->attached[0][0]);
        $this->assertSame(SnippetExecutor::PRIORITY, $events->attached[0][2]);
        $this->assertSame([$executor, 'executeFromMvcEvent'], $events->attached[0][1]);
    }
}
