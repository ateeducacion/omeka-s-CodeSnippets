<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Service;

use CodeSnippets\Service\SafeMode;
use PHPUnit\Framework\TestCase;

class SafeModeTest extends TestCase
{
    /** @var SafeMode */
    private $safeMode;

    protected function setUp(): void
    {
        $this->safeMode = new SafeMode();
        putenv('OMEKA_CODE_SNIPPETS_SAFE_MODE');
    }

    protected function tearDown(): void
    {
        putenv('OMEKA_CODE_SNIPPETS_SAFE_MODE');
    }

    public function testCliSkipsExecution(): void
    {
        $this->assertTrue($this->safeMode->isCli('cli'));
        $this->assertTrue($this->safeMode->shouldSkipExecution([], 'global_admin', 'cli'));
    }

    public function testHttpDoesNotSkipForCliCheck(): void
    {
        $this->assertFalse($this->safeMode->isCli('fpm-fcgi'));
        $this->assertFalse($this->safeMode->shouldSkipExecution([], 'global_admin', 'fpm-fcgi'));
    }

    public function testEmergencyEnvVarSkipsWithoutAuth(): void
    {
        putenv('OMEKA_CODE_SNIPPETS_SAFE_MODE=1');
        $this->assertTrue($this->safeMode->isEmergencySafeMode());
        $this->assertTrue($this->safeMode->shouldSkipExecution([], null, 'fpm-fcgi'));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testEmergencyConstantSkipsWithoutAuth(): void
    {
        define('OMEKA_CODE_SNIPPETS_SAFE_MODE', true);
        $safeMode = new SafeMode();
        $this->assertTrue($safeMode->isEmergencySafeMode());
        $this->assertTrue($safeMode->shouldSkipExecution([], null, 'apache2handler'));
    }

    public function testGlobalAdminUrlSafeMode(): void
    {
        $request = ['snippets-safe-mode' => '1'];
        $this->assertTrue($this->safeMode->isUrlSafeMode($request, 'global_admin'));
        $this->assertTrue($this->safeMode->shouldSkipExecution($request, 'global_admin', 'fpm-fcgi'));
    }

    public function testGlobalAdminWithoutParameterExecutes(): void
    {
        $this->assertFalse($this->safeMode->isUrlSafeMode([], 'global_admin'));
        $this->assertFalse($this->safeMode->shouldSkipExecution([], 'global_admin', 'fpm-fcgi'));
    }

    public function testAnonymousUrlParameterIsIgnored(): void
    {
        $request = ['snippets-safe-mode' => '1'];
        $this->assertFalse($this->safeMode->isUrlSafeMode($request, null));
        $this->assertFalse($this->safeMode->shouldSkipExecution($request, null, 'fpm-fcgi'));
    }

    public function testNonGlobalAdminUrlParameterIsIgnored(): void
    {
        $request = ['snippets-safe-mode' => '1'];
        foreach (['editor', 'reviewer', 'author', 'site_admin', 'researcher'] as $role) {
            $this->assertFalse($this->safeMode->isUrlSafeMode($request, $role), $role);
            $this->assertFalse($this->safeMode->shouldSkipExecution($request, $role, 'fpm-fcgi'), $role);
        }
    }

    public function testPreservedQueryOnlyForAuthorizedUrlMode(): void
    {
        $this->assertSame(
            ['snippets-safe-mode' => '1'],
            $this->safeMode->preservedQuery(['snippets-safe-mode' => '1'], 'global_admin')
        );
        $this->assertSame([], $this->safeMode->preservedQuery(['snippets-safe-mode' => '1'], 'editor'));
        $this->assertSame([], $this->safeMode->preservedQuery([], 'global_admin'));
    }
}
