<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Service;

use CodeSnippets\Db\Schema;
use CodeSnippets\Exception\InvalidImportException;
use CodeSnippets\Exception\InvalidSyntaxException;
use CodeSnippets\Exception\SnippetNotFoundException;
use CodeSnippets\Service\PhpValidator;
use CodeSnippets\Service\SnippetImportExport;
use CodeSnippets\Service\SnippetRepository;
use CodeSnippets\Service\SnippetRepositoryInterface;
use CodeSnippets\Service\SnippetScope;
use CodeSnippets\Service\SnippetService;
use CodeSnippetsTest\Support\InMemorySnippetRepository;
use CodeSnippetsTest\Support\PdoConnection;
use PDO;
use PHPUnit\Framework\TestCase;

class SnippetImportExportTest extends TestCase
{
    /** @var InMemorySnippetRepository */
    private $repository;

    /** @var SnippetService */
    private $snippetService;

    /** @var SnippetImportExport */
    private $service;

    protected function setUp(): void
    {
        $this->repository = new InMemorySnippetRepository();
        $this->snippetService = new SnippetService($this->repository, new PhpValidator());
        $this->service = new SnippetImportExport($this->snippetService, $this->repository);
    }

    // =========================================================================
    // Contract Tests
    // =========================================================================

    public function testSnippetRepositoryInterfaceRequiresTransactional(): void
    {
        $reflection = new \ReflectionClass(SnippetRepositoryInterface::class);
        $this->assertTrue($reflection->hasMethod('transactional'));

        $method = $reflection->getMethod('transactional');
        $this->assertSame(1, $method->getNumberOfParameters());
    }

    // =========================================================================
    // Export Tests
    // =========================================================================

    public function testExportAllWhenNoSnippets(): void
    {
        $export = $this->service->exportAll();

        $this->assertSame(SnippetImportExport::FORMAT, $export['format']);
        $this->assertSame(SnippetImportExport::CURRENT_VERSION, $export['version']);
        $this->assertSame([], $export['snippets']);
    }

    public function testExportOneSnippetReturnsVersionedEnvelope(): void
    {
        $created = $this->snippetService->create([
            'name' => 'Single Snippet',
            'description' => 'A test description',
            'code' => '$x = 1;',
            'priority' => 5,
            'run_scope' => SnippetScope::ADMIN,
            'active' => false,
        ]);

        $exported = $this->service->export((int) $created['id']);

        $this->assertSame(SnippetImportExport::FORMAT, $exported['format']);
        $this->assertSame(SnippetImportExport::CURRENT_VERSION, $exported['version']);
        $this->assertCount(1, $exported['snippets']);

        $snippet = $exported['snippets'][0];
        $this->assertSame([
            'name' => 'Single Snippet',
            'description' => 'A test description',
            'code' => '$x = 1;',
            'priority' => 5,
            'run_scope' => 'admin',
            'active' => false,
        ], $snippet);

        // Verify exclusion of database IDs, timestamps, and error fields
        $this->assertArrayNotHasKey('id', $snippet);
        $this->assertArrayNotHasKey('created', $snippet);
        $this->assertArrayNotHasKey('modified', $snippet);
        $this->assertArrayNotHasKey('last_error_type', $snippet);
        $this->assertArrayNotHasKey('last_error_message', $snippet);
        $this->assertArrayNotHasKey('last_error_line', $snippet);
        $this->assertArrayNotHasKey('last_error_at', $snippet);
    }

    public function testExportAllWithMultipleSnippets(): void
    {
        $this->snippetService->create([
            'name' => 'Snippet A',
            'code' => '$a = 1;',
            'priority' => 10,
        ]);
        $this->snippetService->create([
            'name' => 'Snippet B',
            'code' => '$b = 2;',
            'priority' => 20,
        ]);

        $exported = $this->service->exportAll();

        $this->assertSame(SnippetImportExport::FORMAT, $exported['format']);
        $this->assertSame(SnippetImportExport::CURRENT_VERSION, $exported['version']);
        $this->assertCount(2, $exported['snippets']);
        $this->assertSame('Snippet A', $exported['snippets'][0]['name']);
        $this->assertSame('Snippet B', $exported['snippets'][1]['name']);
    }

    public function testExportMissingSnippetThrowsNotFoundException(): void
    {
        $this->expectException(SnippetNotFoundException::class);
        $this->service->export(999);
    }

    public function testExportJsonIncludesFormatAndVersion(): void
    {
        $created = $this->snippetService->create([
            'name' => 'Json Export',
            'code' => '$y = 2;',
        ]);

        // Export all as JSON
        $jsonAll = $this->service->exportJson();
        $decodedAll = json_decode($jsonAll, true);
        $this->assertSame(SnippetImportExport::FORMAT, $decodedAll['format']);
        $this->assertSame(SnippetImportExport::CURRENT_VERSION, $decodedAll['version']);
        $this->assertCount(1, $decodedAll['snippets']);

        // Export single snippet as JSON
        $jsonSingle = $this->service->exportJson((int) $created['id']);
        $decodedSingle = json_decode($jsonSingle, true);
        $this->assertSame(SnippetImportExport::FORMAT, $decodedSingle['format']);
        $this->assertSame(SnippetImportExport::CURRENT_VERSION, $decodedSingle['version']);
        $this->assertCount(1, $decodedSingle['snippets']);
        $this->assertSame('Json Export', $decodedSingle['snippets'][0]['name']);
        $this->assertSame('$y = 2;', $decodedSingle['snippets'][0]['code']);
    }

    // =========================================================================
    // Import Tests
    // =========================================================================

    public function testImportValidInactiveSnippet(): void
    {
        $snippet = [
            'name' => 'Inactive Snippet',
            'description' => 'Test desc',
            'code' => '$x = 1;',
            'priority' => 15,
            'run_scope' => SnippetScope::FRONT_END,
            'active' => false,
        ];

        $imported = $this->service->import($snippet);

        $this->assertArrayHasKey('id', $imported);
        $this->assertSame('Inactive Snippet', $imported['name']);
        $this->assertSame('Test desc', $imported['description']);
        $this->assertSame('$x = 1;', $imported['code']);
        $this->assertSame(15, $imported['priority']);
        $this->assertSame(SnippetScope::FRONT_END, $imported['run_scope']);
        $this->assertFalse($imported['active']);
    }

    public function testImportValidActiveSnippet(): void
    {
        $snippet = [
            'name' => 'Active Snippet',
            'code' => '$x = 1;',
            'active' => true,
        ];

        $imported = $this->service->import($snippet);

        $this->assertTrue($imported['active']);
        $this->assertSame('$x = 1;', $imported['code']);
    }

    public function testImportRejectsVersionedDocumentWithClearMessage(): void
    {
        $document = [
            'format' => SnippetImportExport::FORMAT,
            'version' => SnippetImportExport::CURRENT_VERSION,
            'snippets' => [
                ['name' => 'Snippet', 'code' => '$x = 1;'],
            ],
        ];

        $this->expectException(InvalidImportException::class);
        $this->expectExceptionMessage('The import() method accepts a single raw snippet array');
        $this->service->import($document);
    }

    public function testImportManyValidVersionedDocument(): void
    {
        $document = [
            'format' => SnippetImportExport::FORMAT,
            'version' => SnippetImportExport::CURRENT_VERSION,
            'snippets' => [
                ['name' => 'First', 'code' => '$a = 1;'],
                ['name' => 'Second', 'code' => '$b = 2;'],
            ],
        ];

        $imported = $this->service->importMany($document);

        $this->assertCount(2, $imported);
        $this->assertSame('First', $imported[0]['name']);
        $this->assertSame('Second', $imported[1]['name']);
        $this->assertCount(2, $this->snippetService->findAll());
    }

    public function testImportManyEmptyDocumentReturnsEmpty(): void
    {
        $document = [
            'format' => SnippetImportExport::FORMAT,
            'version' => SnippetImportExport::CURRENT_VERSION,
            'snippets' => [],
        ];

        $this->assertSame([], $this->service->importMany($document));
    }

    public function testImportPreservesAllPortableFields(): void
    {
        $snippet = [
            'name' => 'Preserved',
            'description' => 'Custom description',
            'code' => '$z = 100;',
            'priority' => 42,
            'run_scope' => SnippetScope::ADMIN,
            'active' => true,
        ];

        $imported = $this->service->import($snippet);

        $this->assertSame('Preserved', $imported['name']);
        $this->assertSame('Custom description', $imported['description']);
        $this->assertSame('$z = 100;', $imported['code']);
        $this->assertSame(42, $imported['priority']);
        $this->assertSame(SnippetScope::ADMIN, $imported['run_scope']);
        $this->assertTrue($imported['active']);
    }

    public function testImportNormalizesPhpOpeningTag(): void
    {
        $snippet = [
            'name' => 'Tagged Snippet',
            'code' => "<?php\n\$result = 'hello';\n?>",
            'active' => true,
        ];

        $imported = $this->service->import($snippet);

        $this->assertSame("\$result = 'hello';", $imported['code']);
    }

    public function testImportActiveInvalidPhpSyntaxIsRejected(): void
    {
        $snippet = [
            'name' => 'Broken Active',
            'code' => 'if (',
            'active' => true,
        ];

        $this->expectException(InvalidSyntaxException::class);
        $this->service->import($snippet);
    }

    public function testImportInactiveInvalidPhpSyntaxIsAllowed(): void
    {
        $snippet = [
            'name' => 'Broken Inactive',
            'code' => 'if (',
            'active' => false,
        ];

        $imported = $this->service->import($snippet);

        $this->assertSame('if (', $imported['code']);
        $this->assertFalse($imported['active']);
    }

    public function testImportManyRejectsUnsupportedVersion(): void
    {
        $document = [
            'format' => SnippetImportExport::FORMAT,
            'version' => 999,
            'snippets' => [
                ['name' => 'Snippet', 'code' => '$x = 1;'],
            ],
        ];

        $this->expectException(InvalidImportException::class);
        $this->expectExceptionMessage('Unsupported format version "999"');
        $this->service->importMany($document);
    }

    public function testImportManyRejectsInvalidFormat(): void
    {
        $document = [
            'format' => 'unsupported-format',
            'version' => 1,
            'snippets' => [],
        ];

        $this->expectException(InvalidImportException::class);
        $this->expectExceptionMessage('Invalid or missing format identifier');
        $this->service->importMany($document);
    }

    public function testImportManyRejectsMissingSnippetsField(): void
    {
        $document = [
            'format' => SnippetImportExport::FORMAT,
            'version' => 1,
        ];

        $this->expectException(InvalidImportException::class);
        $this->expectExceptionMessage('The "snippets" field is required and must be an array.');
        $this->service->importMany($document);
    }

    public function testImportManyRejectsNonArraySnippetsField(): void
    {
        $document = [
            'format' => SnippetImportExport::FORMAT,
            'version' => 1,
            'snippets' => 'not an array',
        ];

        $this->expectException(InvalidImportException::class);
        $this->expectExceptionMessage('The "snippets" field is required and must be an array.');
        $this->service->importMany($document);
    }

    public function testImportManyRejectsNonArraySnippetInList(): void
    {
        $document = [
            'format' => SnippetImportExport::FORMAT,
            'version' => 1,
            'snippets' => [
                'not an array snippet',
            ],
        ];

        $this->expectException(InvalidImportException::class);
        $this->expectExceptionMessage('Snippet at index 0 must be an array.');
        $this->service->importMany($document);
    }

    public function testImportRejectsMissingName(): void
    {
        $this->expectException(InvalidImportException::class);
        $this->expectExceptionMessage('Snippet "name" is required and cannot be empty.');
        $this->service->import(['code' => '$x = 1;']);
    }

    public function testImportRejectsEmptyName(): void
    {
        $this->expectException(InvalidImportException::class);
        $this->expectExceptionMessage('Snippet "name" is required and cannot be empty.');
        $this->service->import(['name' => '   ', 'code' => '$x = 1;']);
    }

    public function testImportRejectsMissingCode(): void
    {
        $this->expectException(InvalidImportException::class);
        $this->expectExceptionMessage('Snippet "code" is required and must be a string.');
        $this->service->import(['name' => 'No Code']);
    }

    public function testImportRejectsNonStringCode(): void
    {
        $this->expectException(InvalidImportException::class);
        $this->expectExceptionMessage('Snippet "code" is required and must be a string.');
        $this->service->import(['name' => 'Bad Code', 'code' => 123]);
    }

    public function testImportRejectsInvalidPriority(): void
    {
        $this->expectException(InvalidImportException::class);
        $this->expectExceptionMessage('Snippet "priority" must be an integer.');
        $this->service->import([
            'name' => 'Bad Priority',
            'code' => '$x = 1;',
            'priority' => 'not_a_number',
        ]);
    }

    public function testImportRejectsFloatPriority(): void
    {
        $this->expectException(InvalidImportException::class);
        $this->expectExceptionMessage('Snippet "priority" must be an integer.');
        $this->service->import([
            'name' => 'Bad Priority',
            'code' => '$x = 1;',
            'priority' => 1.5,
        ]);
    }

    public function testImportRejectsInvalidRunScope(): void
    {
        $this->expectException(InvalidImportException::class);
        $this->expectExceptionMessage('Invalid run_scope "invalid-scope"');
        $this->service->import([
            'name' => 'Bad Scope',
            'code' => '$x = 1;',
            'run_scope' => 'invalid-scope',
        ]);
    }

    public function testImportRejectsInvalidActive(): void
    {
        $this->expectException(InvalidImportException::class);
        $this->expectExceptionMessage('Snippet "active" must be a boolean.');
        $this->service->import([
            'name' => 'Bad Active',
            'code' => '$x = 1;',
            'active' => 'not_boolean',
        ]);
    }

    public function testImportRejectsInvalidDescription(): void
    {
        $this->expectException(InvalidImportException::class);
        $this->expectExceptionMessage('Snippet "description" must be a string or null.');
        $this->service->import([
            'name' => 'Bad Desc',
            'code' => '$x = 1;',
            'description' => ['an', 'array'],
        ]);
    }

    public function testBehaviorWhenOptionalFieldsAreMissing(): void
    {
        $snippet = $this->service->import([
            'name' => 'Minimal Snippet',
            'code' => '$m = 1;',
        ]);

        $this->assertNull($snippet['description']);
        $this->assertSame(SnippetRepository::DEFAULT_PRIORITY, $snippet['priority']);
        $this->assertSame(SnippetScope::DEFAULT, $snippet['run_scope']);
        $this->assertFalse($snippet['active']);
    }

    public function testDuplicateNameBehaviorCreatesDistinctSnippets(): void
    {
        $first = $this->service->import([
            'name' => 'Duplicate Name',
            'code' => '$x = 1;',
        ]);
        $second = $this->service->import([
            'name' => 'Duplicate Name',
            'code' => '$x = 2;',
        ]);

        $this->assertNotSame($first['id'], $second['id']);
        $this->assertSame('Duplicate Name', $first['name']);
        $this->assertSame('Duplicate Name', $second['name']);
        $this->assertCount(2, $this->snippetService->findAll());
    }

    public function testImportManyTransactionalRollbackOnInMemoryRepository(): void
    {
        $document = [
            'format' => SnippetImportExport::FORMAT,
            'version' => SnippetImportExport::CURRENT_VERSION,
            'snippets' => [
                ['name' => 'Valid Inactive', 'code' => '$x = 1;', 'active' => false],
                ['name' => 'Invalid Active', 'code' => 'if (', 'active' => true],
            ],
        ];

        try {
            $this->service->importMany($document);
            $this->fail('Expected InvalidSyntaxException');
        } catch (InvalidSyntaxException $e) {
            // Atomic guarantee: neither snippet is persisted
            $this->assertCount(0, $this->snippetService->findAll());
        }
    }

    public function testImportManyTransactionalRollbackOnDatabaseRepository(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is required');
        }

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec(Schema::createTableSqliteSql());
        $pdo->exec(Schema::createIndexSqliteSql());

        $repository = new SnippetRepository(new PdoConnection($pdo));
        $snippetService = new SnippetService($repository, new PhpValidator());
        $service = new SnippetImportExport($snippetService, $repository);

        $document = [
            'format' => SnippetImportExport::FORMAT,
            'version' => SnippetImportExport::CURRENT_VERSION,
            'snippets' => [
                ['name' => 'Valid Inactive', 'code' => '$x = 1;', 'active' => false],
                ['name' => 'Invalid Active', 'code' => 'if (', 'active' => true],
            ],
        ];

        try {
            $service->importMany($document);
            $this->fail('Expected InvalidSyntaxException');
        } catch (InvalidSyntaxException $e) {
            // Rollback must leave zero rows in the database
            $this->assertCount(0, $snippetService->findAll());
        }
    }

    public function testImportJsonHelper(): void
    {
        $json = json_encode([
            'format' => SnippetImportExport::FORMAT,
            'version' => SnippetImportExport::CURRENT_VERSION,
            'snippets' => [
                ['name' => 'From JSON', 'code' => '$j = 1;'],
            ],
        ]);

        $imported = $this->service->importJson($json);

        $this->assertCount(1, $imported);
        $this->assertSame('From JSON', $imported[0]['name']);
    }

    public function testImportJsonInvalidSyntaxThrowsWithPreservedJsonException(): void
    {
        try {
            $this->service->importJson('{invalid json');
            $this->fail('Expected InvalidImportException');
        } catch (InvalidImportException $e) {
            $this->assertStringContainsString('Invalid JSON input', $e->getMessage());
            $this->assertInstanceOf(\JsonException::class, $e->getPrevious());
        }
    }

    public function testImportJsonNonArrayThrows(): void
    {
        $this->expectException(InvalidImportException::class);
        $this->expectExceptionMessage('Decoded JSON must be an array or object.');
        $this->service->importJson('"just a string"');
    }

    public function testValidateDocumentRejectsNonScalarFormatAndVersion(): void
    {
        try {
            $this->service->importMany(['format' => ['array'], 'version' => 1, 'snippets' => []]);
            $this->fail('Expected InvalidImportException');
        } catch (InvalidImportException $e) {
            $this->assertStringContainsString('Invalid or missing format identifier', $e->getMessage());
        }

        try {
            $this->service->importMany([
                'format' => SnippetImportExport::FORMAT,
                'version' => ['array'],
                'snippets' => [],
            ]);
            $this->fail('Expected InvalidImportException');
        } catch (InvalidImportException $e) {
            $this->assertStringContainsString('Unsupported format version', $e->getMessage());
        }
    }

    public function testValidateSnippetRejectsNonScalarRunScope(): void
    {
        $this->expectException(InvalidImportException::class);
        $this->expectExceptionMessage('Invalid run_scope "array"');
        $this->service->import([
            'name' => 'Non scalar scope',
            'code' => '$x = 1;',
            'run_scope' => ['array'],
        ]);
    }

    // =========================================================================
    // Round Trip Tests
    // =========================================================================

    public function testRoundTripPreservesPortableState(): void
    {
        // 1. Create original snippets with diverse configurations
        $this->snippetService->create([
            'name' => 'Snippet 1',
            'description' => 'Description 1',
            'code' => '$a = "hello";',
            'priority' => 1,
            'run_scope' => SnippetScope::GLOBAL,
            'active' => true,
        ]);
        $this->snippetService->create([
            'name' => 'Snippet 2',
            'description' => null,
            'code' => '$b = "world";',
            'priority' => 20,
            'run_scope' => SnippetScope::ADMIN,
            'active' => false,
        ]);
        $this->snippetService->create([
            'name' => 'Snippet 3',
            'description' => 'Front-end only',
            'code' => '$c = 123;',
            'priority' => 10,
            'run_scope' => SnippetScope::FRONT_END,
            'active' => true,
        ]);

        // 2. Export
        $export1 = $this->service->exportAll();

        // 3. Clear state (simulate fresh environment)
        $freshRepository = new InMemorySnippetRepository();
        $freshSnippetService = new SnippetService($freshRepository, new PhpValidator());
        $freshService = new SnippetImportExport($freshSnippetService, $freshRepository);

        // 4. Import into fresh state
        $freshService->importMany($export1);

        // 5. Export again from fresh state
        $export2 = $freshService->exportAll();

        // 6. Verify exported portable representations match exactly
        $this->assertSame($export1, $export2);
    }

    public function testSingleSnippetRoundTrip(): void
    {
        $created = $this->snippetService->create([
            'name' => 'Single Round Trip',
            'description' => 'Single description',
            'code' => '$s = "single";',
            'priority' => 7,
            'run_scope' => SnippetScope::ADMIN,
            'active' => true,
        ]);

        $export1 = $this->service->export((int) $created['id']);

        $freshRepository = new InMemorySnippetRepository();
        $freshSnippetService = new SnippetService($freshRepository, new PhpValidator());
        $freshService = new SnippetImportExport($freshSnippetService, $freshRepository);

        $freshService->importMany($export1);

        $export2 = $freshService->exportAll();

        $this->assertSame($export1, $export2);
    }

    public function testJsonRoundTrip(): void
    {
        $this->snippetService->create([
            'name' => 'JSON Snippet',
            'description' => 'Testing JSON serialization',
            'code' => '$logger->info("test");',
            'priority' => 15,
            'run_scope' => SnippetScope::GLOBAL,
            'active' => true,
        ]);

        $json1 = $this->service->exportJson();

        $freshRepository = new InMemorySnippetRepository();
        $freshSnippetService = new SnippetService($freshRepository, new PhpValidator());
        $freshService = new SnippetImportExport($freshSnippetService, $freshRepository);

        $freshService->importJson($json1);

        $json2 = $freshService->exportJson();

        $this->assertSame($json1, $json2);
    }
}
