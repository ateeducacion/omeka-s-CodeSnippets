<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Config;

use CodeSnippets\Controller\Admin\SnippetController;
use PHPUnit\Framework\TestCase;

class ModuleConfigTest extends TestCase
{
    /** @var array<string, mixed> */
    private $config;

    protected function setUp(): void
    {
        $this->config = require dirname(__DIR__, 3) . '/config/module.config.php';
    }

    public function testRoutesAreRegistered(): void
    {
        $routes = $this->config['router']['routes']['admin']['child_routes']['code-snippets'];
        $this->assertSame('/code-snippets', $routes['options']['route']);
        $this->assertSame(SnippetController::class, $routes['options']['defaults']['controller']);
        $this->assertSame('index', $routes['options']['defaults']['action']);
        $this->assertArrayHasKey('add', $routes['child_routes']);
        $this->assertArrayHasKey('edit', $routes['child_routes']);
        $this->assertArrayHasKey('activate', $routes['child_routes']);
        $this->assertArrayHasKey('deactivate', $routes['child_routes']);
        $this->assertArrayHasKey('delete', $routes['child_routes']);
        $this->assertSame('/add', $routes['child_routes']['add']['options']['route']);
        $this->assertSame('/:id/edit', $routes['child_routes']['edit']['options']['route']);
        $this->assertSame('\d+', $routes['child_routes']['edit']['options']['constraints']['id']);
    }

    public function testControllerIsRegistered(): void
    {
        $this->assertArrayHasKey(
            SnippetController::class,
            $this->config['controllers']['factories']
        );
    }

    public function testAdminNavigationExists(): void
    {
        $nav = $this->config['navigation']['AdminModule'][0];
        $this->assertSame('Code Snippets', $nav['label']);
        $this->assertSame('admin/code-snippets', $nav['route']);
        $this->assertSame(SnippetController::class, $nav['resource']);
        $this->assertSame('index', $nav['privilege']);
    }

    public function testTranslatorIsConfigured(): void
    {
        $this->assertArrayHasKey('translator', $this->config);
        $pattern = $this->config['translator']['translation_file_patterns'][0];
        $this->assertSame('gettext', $pattern['type']);
        $this->assertSame('%s.mo', $pattern['pattern']);
    }

    public function testNoCatchAllActionRoute(): void
    {
        $codeSnippets = json_encode($this->config['router']['routes']['admin']['child_routes']['code-snippets']);
        $this->assertFalse(strpos($codeSnippets, '[/:action]') !== false);
    }

    public function testModuleIniIdentity(): void
    {
        $ini = file_get_contents(dirname(__DIR__, 3) . '/config/module.ini');
        $this->assertNotFalse($ini);
        $this->assertTrue(strpos($ini, 'name         = "Code Snippets"') !== false);
        $this->assertTrue(strpos($ini, 'configurable = false') !== false);
        $this->assertTrue(strpos($ini, 'omeka_version_constraint = "^4.1.0"') !== false);
        $this->assertTrue(strpos($ini, 'author       = "Área de Tecnología Educativa"') !== false);
    }
}
