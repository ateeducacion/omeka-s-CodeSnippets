<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Service;

use CodeSnippets\Exception\InvalidSyntaxException;
use CodeSnippets\Service\PhpValidator;
use CodeSnippets\Service\SnippetService;
use CodeSnippetsTest\Support\InMemorySnippetRepository;
use PHPUnit\Framework\TestCase;

class SnippetServiceTest extends TestCase
{
    /** @var InMemorySnippetRepository */
    private $repository;

    /** @var SnippetService */
    private $service;

    protected function setUp(): void
    {
        $this->repository = new InMemorySnippetRepository();
        $this->service = new SnippetService($this->repository, new PhpValidator());
    }

    public function testCreateInactiveInvalidCodeIsAllowed(): void
    {
        $snippet = $this->service->create([
            'name' => 'Broken',
            'code' => 'if (',
            'active' => false,
        ]);
        $this->assertFalse($snippet['active']);
        $this->assertSame('if (', $snippet['code']);
    }

    public function testCreateActiveInvalidCodeIsRejected(): void
    {
        $this->expectException(InvalidSyntaxException::class);
        $this->service->create([
            'name' => 'Broken',
            'code' => 'if (',
            'active' => true,
        ]);
    }

    public function testActivateInvalidCodeKeepsInactive(): void
    {
        $snippet = $this->service->create([
            'name' => 'Broken',
            'code' => 'if (',
            'active' => false,
        ]);
        try {
            $this->service->activate((int) $snippet['id']);
            $this->fail('Expected InvalidSyntaxException');
        } catch (InvalidSyntaxException $exception) {
            $after = $this->repository->find((int) $snippet['id']);
            $this->assertFalse($after['active']);
            $this->assertSame('if (', $after['code']);
        }
    }

    public function testInvalidUpdateOfActiveSnippetIsAtomic(): void
    {
        $snippet = $this->service->create([
            'name' => 'Live',
            'code' => '$x = 1;',
            'active' => true,
        ]);
        try {
            $this->service->update((int) $snippet['id'], [
                'name' => 'Changed',
                'code' => 'if (',
                'active' => true,
            ]);
            $this->fail('Expected InvalidSyntaxException');
        } catch (InvalidSyntaxException $exception) {
            $after = $this->repository->find((int) $snippet['id']);
            $this->assertSame('Live', $after['name']);
            $this->assertSame('$x = 1;', $after['code']);
            $this->assertTrue($after['active']);
        }
    }

    public function testActivateValidCode(): void
    {
        $snippet = $this->service->create([
            'name' => 'Ok',
            'code' => '$x = 1;',
            'active' => false,
        ]);
        $activated = $this->service->activate((int) $snippet['id']);
        $this->assertTrue($activated['active']);
    }

    public function testOpeningTagIsNormalizedOnSave(): void
    {
        $snippet = $this->service->create([
            'name' => 'Tagged',
            'code' => "<?php\n\$x = 1;",
            'active' => true,
        ]);
        $this->assertSame("\$x = 1;", $snippet['code']);
    }
}
