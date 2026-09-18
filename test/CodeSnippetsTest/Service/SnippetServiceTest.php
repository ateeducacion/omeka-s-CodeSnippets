<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Service;

use CodeSnippets\Exception\InvalidSyntaxException;
use CodeSnippets\Exception\SnippetNotFoundException;
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
        try {
            $this->service->create([
                'name' => 'Broken',
                'code' => 'if (',
                'active' => true,
            ]);
            $this->fail('Expected InvalidSyntaxException');
        } catch (InvalidSyntaxException $exception) {
            $this->assertNotSame('', $exception->getMessage());
            $this->assertTrue(
                $exception->getSyntaxLine() === null || $exception->getSyntaxLine() >= 1
            );
        }
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

    public function testFindAllAndDelete(): void
    {
        $this->service->create(['name' => 'A', 'code' => '$a = 1;']);
        $this->service->create(['name' => 'B', 'code' => '$b = 1;']);
        $this->assertCount(2, $this->service->findAll());
        $this->service->delete(1);
        $this->assertCount(1, $this->service->findAll());
    }

    public function testFindMissingThrows(): void
    {
        $this->expectException(SnippetNotFoundException::class);
        $this->service->find(99);
    }

    public function testDeactivate(): void
    {
        $snippet = $this->service->create([
            'name' => 'On',
            'code' => '$x = 1;',
            'active' => true,
        ]);
        $off = $this->service->deactivate((int) $snippet['id']);
        $this->assertFalse($off['active']);
    }

    public function testActiveTruthyStrings(): void
    {
        foreach (['1', 'true', 'on', 1] as $value) {
            $snippet = $this->service->create([
                'name' => 'Flag ' . (string) $value,
                'code' => '$x = 1;',
                'active' => $value,
            ]);
            $this->assertTrue($snippet['active'], (string) $value);
        }
    }

    public function testInvalidPriorityIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create([
            'name' => 'Bad',
            'code' => '$x = 1;',
            'priority' => '1.5',
        ]);
    }

    public function testUpdateOnDatabaseRepositoryUsesTransaction(): void
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required');
        }
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(\CodeSnippets\Db\Schema::createTableSqliteSql());
        $pdo->exec(\CodeSnippets\Db\Schema::createIndexSqliteSql());
        $repository = new \CodeSnippets\Service\SnippetRepository(
            new \CodeSnippetsTest\Support\PdoConnection($pdo)
        );
        $service = new SnippetService($repository, new PhpValidator());
        $created = $service->create(['name' => 'Db', 'code' => '$x = 1;']);
        $updated = $service->update((int) $created['id'], [
            'name' => 'Db2',
            'code' => '$y = 2;',
            'active' => false,
        ]);
        $this->assertSame('Db2', $updated['name']);
        $this->assertSame('$y = 2;', $updated['code']);
    }
}
