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
}
