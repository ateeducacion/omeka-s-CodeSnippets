<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Service;

use CodeSnippets\Db\Schema;
use CodeSnippets\Exception\SnippetNotFoundException;
use CodeSnippets\Service\SnippetRepository;
use CodeSnippetsTest\Support\PdoConnection;
use PDO;
use PHPUnit\Framework\TestCase;

class SnippetRepositoryTest extends TestCase
{
    /** @var SnippetRepository */
    private $repository;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required for repository tests');
        }
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(Schema::createTableSqliteSql());
        $pdo->exec(Schema::createIndexSqliteSql());
        $this->repository = new SnippetRepository(new PdoConnection($pdo));
    }

    public function testCreateReadUpdateActivateDeactivateDelete(): void
    {
        $created = $this->repository->create([
            'name' => 'One',
            'description' => 'Desc',
            'code' => '$x = 1;',
            'priority' => 7,
            'active' => false,
        ]);
        $this->assertSame(1, $created['id']);
        $this->assertSame('One', $created['name']);
        $this->assertSame(7, $created['priority']);
        $this->assertFalse($created['active']);

        $found = $this->repository->find(1);
        $this->assertSame('Desc', $found['description']);

        $updated = $this->repository->update(1, ['name' => 'Two', 'code' => '$x = 2;']);
        $this->assertSame('Two', $updated['name']);
        $this->assertSame('$x = 2;', $updated['code']);

        $activated = $this->repository->activate(1);
        $this->assertTrue($activated['active']);
        $this->assertCount(1, $this->repository->findActiveOrdered());

        $deactivated = $this->repository->deactivate(1);
        $this->assertFalse($deactivated['active']);
        $this->assertCount(0, $this->repository->findActiveOrdered());

        $this->repository->delete(1);
        $this->assertNull($this->repository->find(1));
    }

    public function testDefaultPriorityIsTen(): void
    {
        $created = $this->repository->create([
            'name' => 'Default',
            'code' => '$x = 1;',
        ]);
        $this->assertSame(10, $created['priority']);
    }

    public function testNegativePriorityIsAllowed(): void
    {
        $created = $this->repository->create([
            'name' => 'Early',
            'code' => '$x = 1;',
            'priority' => -5,
            'active' => true,
        ]);
        $this->assertSame(-5, $created['priority']);
    }

    public function testDuplicateNamesAreAllowed(): void
    {
        $this->repository->create(['name' => 'Same', 'code' => '$a = 1;']);
        $second = $this->repository->create(['name' => 'Same', 'code' => '$b = 2;']);
        $this->assertSame('Same', $second['name']);
        $this->assertSame(2, $second['id']);
    }

    public function testFindActiveOrderedSqlUsesPriorityThenId(): void
    {
        $sql = Schema::selectActiveOrderedSql();
        $this->assertStringContainsString('WHERE active = ?', $sql);
        $this->assertStringContainsString('ORDER BY priority ASC, id ASC', $sql);
    }

    public function testRecordErrorPersistsMetadata(): void
    {
        $this->repository->create(['name' => 'Err', 'code' => '$x = 1;']);
        $this->repository->recordError(1, 'RuntimeException', 'boom', 4);
        $found = $this->repository->find(1);
        $this->assertSame('RuntimeException', $found['last_error_type']);
        $this->assertSame('boom', $found['last_error_message']);
        $this->assertSame(4, $found['last_error_line']);
        $this->assertNotNull($found['last_error_at']);
    }

    public function testMissingIdThrows(): void
    {
        $this->expectException(SnippetNotFoundException::class);
        $this->repository->delete(99);
    }

    public function testCreateTableSqlHasExecutionIndex(): void
    {
        $sql = Schema::createTableSql();
        $this->assertStringContainsString('CREATE TABLE `code_snippet`', $sql);
        $this->assertStringContainsString('idx_code_snippet_active_priority_id', $sql);
        $this->assertStringContainsString('(`active`, `priority`, `id`)', $sql);
        $this->assertStringContainsString('DEFAULT 10', $sql);
    }
}
