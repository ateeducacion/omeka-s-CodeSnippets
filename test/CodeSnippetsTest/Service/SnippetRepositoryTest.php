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

    public function testFindAllReturnsHydratedRows(): void
    {
        $this->repository->create(['name' => 'A', 'code' => '$a = 1;']);
        $this->repository->create(['name' => 'B', 'code' => '$b = 1;']);
        $all = $this->repository->findAll();
        $this->assertCount(2, $all);
        $this->assertSame('A', $all[0]['name']);
    }

    public function testEmptyNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repository->create(['name' => '  ', 'code' => '$x = 1;']);
    }

    public function testLongNameIsTruncated(): void
    {
        $name = str_repeat('n', 300);
        $created = $this->repository->create(['name' => $name, 'code' => '$x = 1;']);
        $this->assertSame(255, strlen($created['name']));
    }

    public function testEmptyDescriptionBecomesNull(): void
    {
        $created = $this->repository->create([
            'name' => 'Desc',
            'description' => '',
            'code' => '$x = 1;',
        ]);
        $this->assertNull($created['description']);
    }

    public function testPriorityNormalization(): void
    {
        $fromString = $this->repository->create([
            'name' => 'S',
            'code' => '$x = 1;',
            'priority' => '-3',
        ]);
        $this->assertSame(-3, $fromString['priority']);
        $fromEmpty = $this->repository->create([
            'name' => 'E',
            'code' => '$x = 1;',
            'priority' => '',
        ]);
        $this->assertSame(10, $fromEmpty['priority']);
        $fromNumeric = $this->repository->create([
            'name' => 'N',
            'code' => '$x = 1;',
            'priority' => '7.9',
        ]);
        $this->assertSame(7, $fromNumeric['priority']);
    }

    public function testInvalidPriorityRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repository->create([
            'name' => 'Bad',
            'code' => '$x = 1;',
            'priority' => 'late',
        ]);
    }

    public function testTransactionalCommitAndRollback(): void
    {
        $created = $this->repository->transactional(function () {
            return $this->repository->create(['name' => 'Tx', 'code' => '$x = 1;']);
        });
        $this->assertSame('Tx', $created['name']);

        try {
            $this->repository->transactional(function () {
                $this->repository->create(['name' => 'Nope', 'code' => '$x = 1;']);
                throw new \RuntimeException('boom');
            });
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $exception) {
            $this->assertSame('boom', $exception->getMessage());
        }
        $this->assertCount(1, $this->repository->findAll());
    }

    public function testRecordErrorTruncatesType(): void
    {
        $this->repository->create(['name' => 'Err', 'code' => '$x = 1;']);
        $this->repository->recordError(1, str_repeat('E', 300), 'msg', null);
        $found = $this->repository->find(1);
        $this->assertSame(255, strlen((string) $found['last_error_type']));
        $this->assertNull($found['last_error_line']);
    }

    public function testUpdatePartialFields(): void
    {
        $this->repository->create([
            'name' => 'Old',
            'description' => 'D',
            'code' => '$x = 1;',
            'priority' => 1,
            'active' => false,
        ]);
        $updated = $this->repository->update(1, [
            'description' => 'New d',
            'priority' => 2,
            'active' => true,
        ]);
        $this->assertSame('Old', $updated['name']);
        $this->assertSame('New d', $updated['description']);
        $this->assertSame(2, $updated['priority']);
        $this->assertTrue($updated['active']);
    }
}
