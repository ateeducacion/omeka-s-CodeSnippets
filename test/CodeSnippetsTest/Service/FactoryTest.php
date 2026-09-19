<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Service;

use CodeSnippets\Controller\Admin\SnippetController;
use CodeSnippets\Controller\Factory\SnippetControllerFactory;
use CodeSnippets\Service\Factory\SnippetExecutorFactory;
use CodeSnippets\Service\Factory\SnippetImportExportFactory;
use CodeSnippets\Service\Factory\SnippetRepositoryFactory;
use CodeSnippets\Service\Factory\SnippetServiceFactory;
use CodeSnippets\Service\PhpValidator;
use CodeSnippets\Service\SafeMode;
use CodeSnippets\Service\SnippetEvaluator;
use CodeSnippets\Service\SnippetExecutor;
use CodeSnippets\Service\SnippetImportExport;
use CodeSnippets\Service\SnippetRepository;
use CodeSnippets\Service\SnippetService;
use CodeSnippetsTest\Support\FakeContainer;
use CodeSnippetsTest\Support\InMemorySnippetRepository;
use CodeSnippetsTest\Support\LoggerSpy;
use CodeSnippetsTest\Support\PdoConnection;
use PDO;
use PHPUnit\Framework\TestCase;

class FactoryTest extends TestCase
{
    public function testSnippetControllerFactory(): void
    {
        $container = new FakeContainer([
            SnippetService::class => new SnippetService(
                new InMemorySnippetRepository(),
                new PhpValidator()
            ),
            SafeMode::class => new SafeMode(),
        ]);
        $factory = new SnippetControllerFactory();
        $controller = $factory($container, SnippetController::class);
        $this->assertInstanceOf(SnippetController::class, $controller);
    }

    public function testSnippetServiceFactory(): void
    {
        $container = new FakeContainer([
            SnippetRepository::class => new InMemorySnippetRepository(),
            PhpValidator::class => new PhpValidator(),
        ]);
        $factory = new SnippetServiceFactory();
        $service = $factory($container, SnippetService::class);
        $this->assertInstanceOf(SnippetService::class, $service);
    }

    public function testSnippetRepositoryFactory(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required');
        }
        $pdo = new PDO('sqlite::memory:');
        $container = new FakeContainer([
            'Omeka\Connection' => new PdoConnection($pdo),
        ]);
        $factory = new SnippetRepositoryFactory();
        $repository = $factory($container, SnippetRepository::class);
        $this->assertInstanceOf(SnippetRepository::class, $repository);
    }

    public function testSnippetExecutorFactoryWithoutOptionalServices(): void
    {
        $container = new FakeContainer([
            SnippetRepository::class => new InMemorySnippetRepository(),
            SafeMode::class => new SafeMode(),
            SnippetEvaluator::class => new SnippetEvaluator(),
            PhpValidator::class => new PhpValidator(),
        ]);
        $factory = new SnippetExecutorFactory();
        $executor = $factory($container, SnippetExecutor::class);
        $this->assertInstanceOf(SnippetExecutor::class, $executor);
    }

    public function testSnippetExecutorFactoryWithLoggerAndAuth(): void
    {
        $auth = new class {
            public function getIdentity()
            {
                return null;
            }
        };
        $container = new FakeContainer([
            SnippetRepository::class => new InMemorySnippetRepository(),
            SafeMode::class => new SafeMode(),
            SnippetEvaluator::class => new SnippetEvaluator(),
            PhpValidator::class => new PhpValidator(),
            'Omeka\Logger' => new LoggerSpy(),
            'Omeka\AuthenticationService' => $auth,
        ]);
        $factory = new SnippetExecutorFactory();
        $executor = $factory($container, SnippetExecutor::class);
        $this->assertInstanceOf(SnippetExecutor::class, $executor);
    }

    public function testSnippetImportExportFactory(): void
    {
        $container = new FakeContainer([
            SnippetService::class => new SnippetService(
                new InMemorySnippetRepository(),
                new PhpValidator()
            ),
            SnippetRepository::class => new InMemorySnippetRepository(),
        ]);
        $factory = new SnippetImportExportFactory();
        $service = $factory($container, SnippetImportExport::class);
        $this->assertInstanceOf(SnippetImportExport::class, $service);
    }
}
