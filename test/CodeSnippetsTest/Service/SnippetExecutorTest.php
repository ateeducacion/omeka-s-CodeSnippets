<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Service;

use CodeSnippets\Service\PhpValidator;
use CodeSnippets\Service\SafeMode;
use CodeSnippets\Service\SnippetEvaluator;
use CodeSnippets\Service\SnippetExecutor;
use CodeSnippetsTest\Support\InMemorySnippetRepository;
use CodeSnippetsTest\Support\LoggerSpy;
use PHPUnit\Framework\TestCase;

class SnippetExecutorTest extends TestCase
{
    /** @var InMemorySnippetRepository */
    private $repository;

    /** @var LoggerSpy */
    private $logger;

    /** @var SnippetExecutor */
    private $executor;

    protected function setUp(): void
    {
        $this->repository = new InMemorySnippetRepository();
        $this->logger = new LoggerSpy();
        $this->executor = $this->makeExecutor();
        $GLOBALS['code_snippets_exec_log'] = [];
    }

    public function testPriorityOrderUsesIdAsTiebreaker(): void
    {
        $this->repository->create([
            'name' => 'A',
            'code' => '$GLOBALS["code_snippets_exec_log"][] = "A";',
            'priority' => 20,
            'active' => true,
        ]);
        $this->repository->create([
            'name' => 'B',
            'code' => '$GLOBALS["code_snippets_exec_log"][] = "B";',
            'priority' => 5,
            'active' => true,
        ]);
        $c = $this->repository->create([
            'name' => 'C',
            'code' => '$GLOBALS["code_snippets_exec_log"][] = "C";',
            'priority' => 10,
            'active' => true,
        ]);
        $d = $this->repository->create([
            'name' => 'D',
            'code' => '$GLOBALS["code_snippets_exec_log"][] = "D";',
            'priority' => 10,
            'active' => true,
        ]);
        $this->assertLessThan($d['id'], $c['id']);

        $this->executor->run(null, null, [], 'global_admin', 'fpm-fcgi');
        $this->assertSame(['B', 'C', 'D', 'A'], $GLOBALS['code_snippets_exec_log']);
    }

    public function testInactiveSnippetIsNeverExecuted(): void
    {
        $this->repository->create([
            'name' => 'inactive',
            'code' => '$GLOBALS["code_snippets_exec_log"][] = "inactive";',
            'priority' => 1,
            'active' => false,
        ]);
        $this->repository->create([
            'name' => 'active',
            'code' => '$GLOBALS["code_snippets_exec_log"][] = "active";',
            'priority' => 10,
            'active' => true,
        ]);

        $this->executor->run(null, null, [], 'global_admin', 'fpm-fcgi');
        $this->assertSame(['active'], $GLOBALS['code_snippets_exec_log']);
    }

    public function testEachSnippetRunsAtMostOncePerRequest(): void
    {
        $this->repository->create([
            'name' => 'once',
            'code' => '$GLOBALS["code_snippets_exec_log"][] = "once";',
            'active' => true,
        ]);

        $first = $this->executor->run(null, null, [], 'global_admin', 'fpm-fcgi');
        $second = $this->executor->run(null, null, [], 'global_admin', 'fpm-fcgi');

        $this->assertSame(1, $first);
        $this->assertSame(0, $second);
        $this->assertSame(['once'], $GLOBALS['code_snippets_exec_log']);
        $this->assertSame(1, $this->repository->findActiveCalls);
    }

    public function testThrowableIsolationAllowsLaterSnippets(): void
    {
        $this->repository->create([
            'name' => 'A',
            'code' => 'throw new \\RuntimeException("Intentional test error");',
            'priority' => 1,
            'active' => true,
        ]);
        $this->repository->create([
            'name' => 'B',
            'code' => '$GLOBALS["code_snippets_exec_log"][] = "B";',
            'priority' => 2,
            'active' => true,
        ]);

        $this->executor->run(null, null, [], 'global_admin', 'fpm-fcgi');

        $this->assertSame(['B'], $GLOBALS['code_snippets_exec_log']);
        $failed = $this->repository->find(1);
        $this->assertSame('RuntimeException', $failed['last_error_type']);
        $this->assertSame('Intentional test error', $failed['last_error_message']);
        $this->assertNotNull($failed['last_error_line']);
        $this->assertNotNull($failed['last_error_at']);
        $this->assertNotEmpty($this->logger->messages);
        $this->assertStringContainsString('snippet #1', $this->logger->messages[0]);
        $this->assertStringContainsString('A', $this->logger->messages[0]);
        $this->assertStringNotContainsString('throw new', $this->logger->messages[0]);
    }

    public function testSuccessfulExecutionDoesNotWriteErrorState(): void
    {
        $created = $this->repository->create([
            'name' => 'ok',
            'code' => '$GLOBALS["code_snippets_exec_log"][] = "ok";',
            'active' => true,
        ]);
        $this->executor->run(null, null, [], 'global_admin', 'fpm-fcgi');
        $after = $this->repository->find((int) $created['id']);
        $this->assertNull($after['last_error_type']);
        $this->assertNull($after['last_error_message']);
    }

    public function testCliSkipsBeforeLoadingSnippets(): void
    {
        $this->repository->create([
            'name' => 'cli',
            'code' => '$GLOBALS["code_snippets_exec_log"][] = "cli";',
            'active' => true,
        ]);
        $count = $this->executor->run(null, null, [], 'global_admin', 'cli');
        $this->assertSame(0, $count);
        $this->assertSame([], $GLOBALS['code_snippets_exec_log']);
        $this->assertSame(0, $this->repository->findActiveCalls);
    }

    public function testUrlSafeModeForGlobalAdminSkipsBeforeLoading(): void
    {
        $this->repository->create([
            'name' => 'skip',
            'code' => '$GLOBALS["code_snippets_exec_log"][] = "skip";',
            'active' => true,
        ]);
        $count = $this->executor->run(
            null,
            null,
            ['snippets-safe-mode' => '1'],
            'global_admin',
            'fpm-fcgi'
        );
        $this->assertSame(0, $count);
        $this->assertSame(0, $this->repository->findActiveCalls);
    }

    public function testAnonymousSafeModeParameterDoesNotSkip(): void
    {
        $this->repository->create([
            'name' => 'run',
            'code' => '$GLOBALS["code_snippets_exec_log"][] = "run";',
            'active' => true,
        ]);
        $this->executor->run(null, null, ['snippets-safe-mode' => '1'], null, 'fpm-fcgi');
        $this->assertSame(['run'], $GLOBALS['code_snippets_exec_log']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testEmergencyConstantSkipsBeforeLoading(): void
    {
        define('OMEKA_CODE_SNIPPETS_SAFE_MODE', true);
        $repository = new InMemorySnippetRepository();
        $repository->create([
            'name' => 'emergency',
            'code' => '$GLOBALS["code_snippets_exec_log"][] = "emergency";',
            'active' => true,
        ]);
        $executor = new SnippetExecutor(
            $repository,
            new SafeMode(),
            new SnippetEvaluator(),
            new PhpValidator(),
            new LoggerSpy(),
            null
        );
        $GLOBALS['code_snippets_exec_log'] = [];
        $count = $executor->run(null, null, [], 'global_admin', 'fpm-fcgi');
        $this->assertSame(0, $count);
        $this->assertSame(0, $repository->findActiveCalls);
        $this->assertSame([], $GLOBALS['code_snippets_exec_log']);
    }

    public function testServicesAndEventAreExposed(): void
    {
        $this->repository->create([
            'name' => 'ctx',
            'code' => '$GLOBALS["code_snippets_ctx"] = [$services, $event];',
            'active' => true,
        ]);
        $services = new \stdClass();
        $event = new \stdClass();
        $this->executor->run($services, $event, [], 'global_admin', 'fpm-fcgi');
        $this->assertSame([$services, $event], $GLOBALS['code_snippets_ctx']);
    }

    public function testEventNameAndPriorityMatchOmekaRouteLifecycle(): void
    {
        $this->assertSame('route', SnippetExecutor::EVENT_NAME);
        $this->assertSame(-10, SnippetExecutor::PRIORITY);
        $this->assertLessThan(1, SnippetExecutor::PRIORITY);
    }

    public function testAdminScopeDoesNotRunOnFrontEnd(): void
    {
        $this->repository->create([
            'name' => 'admin-only',
            'code' => '$GLOBALS["code_snippets_exec_log"][] = "admin";',
            'active' => true,
            'run_scope' => 'admin',
        ]);
        $this->repository->create([
            'name' => 'everywhere',
            'code' => '$GLOBALS["code_snippets_exec_log"][] = "global";',
            'active' => true,
            'run_scope' => 'global',
        ]);

        $this->executor->run(
            null,
            new \CodeSnippetsTest\Support\FakeMvcEvent(false),
            [],
            'global_admin',
            'fpm-fcgi'
        );
        $this->assertSame(['global'], $GLOBALS['code_snippets_exec_log']);
    }

    public function testFrontEndScopeDoesNotRunInAdmin(): void
    {
        $this->repository->create([
            'name' => 'front-only',
            'code' => '$GLOBALS["code_snippets_exec_log"][] = "front";',
            'active' => true,
            'run_scope' => 'front-end',
        ]);
        $this->repository->create([
            'name' => 'everywhere',
            'code' => '$GLOBALS["code_snippets_exec_log"][] = "global";',
            'active' => true,
            'run_scope' => 'global',
        ]);

        $this->executor->run(
            null,
            new \CodeSnippetsTest\Support\FakeMvcEvent(true),
            [],
            'global_admin',
            'fpm-fcgi'
        );
        $this->assertSame(['global'], $GLOBALS['code_snippets_exec_log']);
    }

    public function testMatchingScopeRuns(): void
    {
        $this->repository->create([
            'name' => 'front-only',
            'code' => '$GLOBALS["code_snippets_exec_log"][] = "front";',
            'active' => true,
            'run_scope' => 'front-end',
        ]);

        $this->executor->run(
            null,
            new \CodeSnippetsTest\Support\FakeMvcEvent(false),
            [],
            'global_admin',
            'fpm-fcgi'
        );
        $this->assertSame(['front'], $GLOBALS['code_snippets_exec_log']);
    }

    private function makeExecutor(): SnippetExecutor
    {
        return new SnippetExecutor(
            $this->repository,
            new SafeMode(),
            new SnippetEvaluator(),
            new PhpValidator(),
            $this->logger,
            null
        );
    }
}
