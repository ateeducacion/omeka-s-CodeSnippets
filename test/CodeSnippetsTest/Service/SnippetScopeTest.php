<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Service;

use CodeSnippets\Service\SnippetScope;
use CodeSnippetsTest\Support\FakeMvcEvent;
use PHPUnit\Framework\TestCase;

class SnippetScopeTest extends TestCase
{
    public function testUnknownValuesFallBackToGlobal(): void
    {
        $this->assertSame(SnippetScope::GLOBAL, SnippetScope::normalize('nope'));
        $this->assertSame(SnippetScope::GLOBAL, SnippetScope::normalize(null));
        $this->assertSame(SnippetScope::ADMIN, SnippetScope::normalize('admin'));
        $this->assertSame(SnippetScope::FRONT_END, SnippetScope::normalize('front-end'));
    }

    public function testGlobalMatchesEveryRequest(): void
    {
        $this->assertTrue(SnippetScope::matches(SnippetScope::GLOBAL, SnippetScope::ADMIN));
        $this->assertTrue(SnippetScope::matches(SnippetScope::GLOBAL, SnippetScope::FRONT_END));
    }

    public function testAdminDoesNotRunOnFrontEnd(): void
    {
        $this->assertTrue(SnippetScope::matches(SnippetScope::ADMIN, SnippetScope::ADMIN));
        $this->assertFalse(SnippetScope::matches(SnippetScope::ADMIN, SnippetScope::FRONT_END));
    }

    public function testFrontEndDoesNotRunInAdmin(): void
    {
        $this->assertTrue(SnippetScope::matches(SnippetScope::FRONT_END, SnippetScope::FRONT_END));
        $this->assertFalse(SnippetScope::matches(SnippetScope::FRONT_END, SnippetScope::ADMIN));
    }

    public function testFromMvcEventUsesAdminFlag(): void
    {
        $this->assertSame(
            SnippetScope::ADMIN,
            SnippetScope::fromMvcEvent(new FakeMvcEvent(true))
        );
        $this->assertSame(
            SnippetScope::FRONT_END,
            SnippetScope::fromMvcEvent(new FakeMvcEvent(false))
        );
        $this->assertSame(SnippetScope::FRONT_END, SnippetScope::fromMvcEvent(null));
    }

    public function testShortLabelsCoverEveryScope(): void
    {
        $this->assertSame(SnippetScope::all(), array_keys(SnippetScope::shortLabels()));
        $this->assertSame('Everywhere', SnippetScope::shortLabels()[SnippetScope::GLOBAL]);
    }
}
