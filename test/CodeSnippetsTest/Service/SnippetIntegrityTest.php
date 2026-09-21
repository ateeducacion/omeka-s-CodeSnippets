<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Service;

use CodeSnippets\Db\Schema;
use CodeSnippets\Exception\SnippetIntegrityException;
use CodeSnippets\Service\PhpValidator;
use CodeSnippets\Service\SafeMode;
use CodeSnippets\Service\SnippetEvaluator;
use CodeSnippets\Service\SnippetExecutor;
use CodeSnippets\Service\SnippetImportExport;
use CodeSnippets\Service\SnippetRepository;
use CodeSnippets\Service\SnippetService;
use CodeSnippets\Service\SnippetSigner;
use CodeSnippetsTest\Support\LoggerSpy;
use CodeSnippetsTest\Support\PdoConnection;
use PHPUnit\Framework\TestCase;

class SnippetIntegrityTest extends TestCase
{
    private const KEY = '0123456789abcdef0123456789abcdef';

    private $pdo;
    private $repository;
    private $signer;
    private $service;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(Schema::createTableSqliteSql());
        $this->repository = new SnippetRepository(new PdoConnection($this->pdo));
        $this->signer = new SnippetSigner(self::KEY);
        $this->service = new SnippetService($this->repository, new PhpValidator(), $this->signer);
        $GLOBALS['integrity_runs'] = [];
    }

    public function testTrustedWritesSignFinalStateAndDeactivationClearsSignature(): void
    {
        $row = $this->service->create($this->data());
        $this->assertTrue($this->signer->verify($row));
        foreach ([
            ['code' => '$x = 2;'], ['priority' => -10], ['run_scope' => 'front-end'],
            ['name' => 'Renamed'], ['description' => 'New description'],
        ] as $change) {
            $updated = $this->service->update($row['id'], $change);
            $this->assertTrue($this->signer->verify($updated));
            $this->assertNotSame($row['signature'], $updated['signature']);
            $row = $updated;
        }
        $off = $this->service->deactivate($row['id']);
        $this->assertFalse($off['active']);
        $this->assertNull($off['signature']);
        $this->assertTrue($this->signer->verify($this->service->activate($row['id'])));
        $inactive = $this->service->create(array_replace($this->data(), ['active' => false]));
        $this->assertNull($inactive['signature']);
    }

    public static function sqlTampering(): array
    {
        return [
            ['signature', null], ['signature', 'forged'],
            ['signature', 'hmac-sha256:v2:' . str_repeat('a', 64)], ['name', "\xff"],
            ['code', '$GLOBALS["integrity_runs"][] = "attacker";'],
            ['priority', 999], ['run_scope', 'front-end'], ['name', 'Forged name'],
            ['description', 'Forged description'], ['id', 123],
        ];
    }

    /** @dataProvider sqlTampering */
    public function testDirectSqlTamperingNeverReachesEvaluator(string $field, $value): void
    {
        $row = $this->service->create($this->data());
        $statement = $this->pdo->prepare('UPDATE code_snippet SET ' . $field . ' = ? WHERE id = ?');
        $statement->execute([$value, $row['id']]);
        $evaluator = $this->createMock(SnippetEvaluator::class);
        $evaluator->expects($this->never())->method('evaluate');
        $logger = new LoggerSpy();
        $executor = $this->executor($evaluator, $logger);
        $this->assertSame(0, $executor->run(null, null, [], null, 'fpm-fcgi'));
        $this->assertSame([], $executor->getExecutedIds());
        $failed = $this->repository->find($field === 'id' ? 123 : $row['id']);
        $this->assertSame(SnippetIntegrityException::class, $failed['last_error_type']);
        $this->assertSame('Snippet integrity verification failed; execution skipped.', $failed['last_error_message']);
        $this->assertStringNotContainsString(self::KEY, implode('', $logger->messages));
        $this->assertStringNotContainsString($row['signature'], implode('', $logger->messages));
        $this->assertStringNotContainsString('Forged name', implode('', $logger->messages));
    }

    public function testSqlActivationAndCopyingSignatureDoNotCreateTrust(): void
    {
        $inactive = $this->service->create(array_replace($this->data(), ['active' => false]));
        $this->pdo->exec('UPDATE code_snippet SET active = 1');
        $valid = $this->service->create($this->data());
        $copy = $this->repository->create($valid);
        $this->repository->setSignature($copy['id'], $valid['signature']);
        $this->assertSame(1, $this->executor()->run(null, null, [], null, 'fpm-fcgi'));
        $this->assertSame(['trusted'], $GLOBALS['integrity_runs']);
        $this->assertNull($this->repository->find($inactive['id'])['signature']);
        $this->assertFalse($this->signer->verify($this->repository->find($copy['id'])));
    }

    public function testEnablingSigningDoesNotTrustExistingUnsignedActiveRows(): void
    {
        $this->repository->create($this->data());
        $this->assertSame(0, $this->executor()->run(null, null, [], null, 'fpm-fcgi'));
        $this->assertNull($this->repository->find(1)['signature']);
        $this->service->update(1, ['code' => $this->data()['code']]);
        $this->assertSame(1, $this->executor()->run(null, null, [], null, 'fpm-fcgi'));
    }

    public function testRotationBlocksUntilExplicitSaveAndDiagnosticsDoNotBreakTrust(): void
    {
        $row = $this->service->create($this->data());
        $this->signer = new SnippetSigner(str_repeat('r', 32));
        $this->assertSame(0, $this->executor()->run(null, null, [], null, 'fpm-fcgi'));
        $rotatedService = new SnippetService($this->repository, new PhpValidator(), $this->signer);
        $updated = $rotatedService->update($row['id'], []);
        $this->assertNotSame($row['signature'], $updated['signature']);
        $this->repository->recordError($row['id'], 'Test', 'Test', 10);
        $this->assertSame(1, $this->executor()->run(null, null, [], null, 'fpm-fcgi'));
    }

    public function testMisconfiguredKeyBlocksExecutionAndRollsBackActiveWrites(): void
    {
        $this->signer = new SnippetSigner('short');
        $this->service = new SnippetService($this->repository, new PhpValidator(), $this->signer);
        $row = $this->repository->create($this->data());
        $logger = new LoggerSpy();
        $this->assertSame(0, $this->executor(null, $logger)->run(null, null, [], null, 'fpm-fcgi'));
        $this->assertStringContainsString('signing configuration error', $logger->messages[0]);
        foreach (['create', 'update', 'activate'] as $operation) {
            try {
                if ($operation === 'create') {
                    $this->service->create($this->data());
                } elseif ($operation === 'update') {
                    $this->service->update($row['id'], ['name' => 'Changed']);
                } else {
                    $this->service->activate($row['id']);
                }
                $this->fail('Expected signing failure.');
            } catch (SnippetIntegrityException $e) {
                $this->assertSame([$row], $this->repository->findAll());
            }
        }
        $this->assertFalse($this->service->deactivate($row['id'])['active']);
    }

    public function testSignaturePersistenceFailureRollsBackCreateAndUpdate(): void
    {
        $repository = new class (new PdoConnection($this->pdo)) extends SnippetRepository {
            public function setSignature(int $id, ?string $signature): array
            {
                throw new \RuntimeException('signature storage failed');
            }
        };
        $service = new SnippetService($repository, new PhpValidator(), $this->signer);
        try {
            $service->create($this->data());
            $this->fail('Expected failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame([], $repository->findAll());
        }
        $original = $this->service->create($this->data());
        try {
            $service->update($original['id'], ['code' => '$x = 2;']);
            $this->fail('Expected failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame($original, $repository->find($original['id']));
        }
    }

    public function testMalformedPayloadRollsBackSigning(): void
    {
        try {
            $this->service->create(array_replace($this->data(), ['description' => "\xff"]));
            $this->fail('Expected failure.');
        } catch (SnippetIntegrityException $e) {
            $this->assertSame([], $this->repository->findAll());
        }
    }

    public function testImportsArePortableLocallySignedAndAtomic(): void
    {
        $source = $this->service->create($this->data());
        $exporter = new SnippetImportExport($this->service, $this->repository);
        $document = $exporter->exportAll();
        $this->assertArrayNotHasKey('signature', $document['snippets'][0]);
        $this->assertStringNotContainsString(self::KEY, $exporter->exportJson());
        $document['snippets'][0]['signature'] = $source['signature'];
        $destinationSigner = new SnippetSigner(str_repeat('d', 32));
        $destination = new SnippetImportExport(
            new SnippetService($this->repository, new PhpValidator(), $destinationSigner),
            $this->repository
        );
        $imported = $destination->importMany($document)[0];
        $this->assertTrue($destinationSigner->verify($imported));
        $this->assertFalse($this->signer->verify($imported));
        $this->assertSame($exporter->export($source['id']), $destination->export($imported['id']));
        $document['snippets'][] = array_replace($this->data(), ['description' => "\xff"]);
        try {
            $destination->importMany($document);
            $this->fail('Expected failure.');
        } catch (SnippetIntegrityException $e) {
            $this->assertCount(2, $this->repository->findAll());
        }
    }

    public function testSafeModeRemainsIndependent(): void
    {
        $this->service->create($this->data());
        $count = $this->executor()->run(null, null, ['snippets-safe-mode' => '1'], 'global_admin', 'fpm-fcgi');
        $this->assertSame(0, $count);
        $this->assertSame(0, $this->executor()->run(null, null, [], null, 'cli'));
        putenv('OMEKA_CODE_SNIPPETS_SAFE_MODE=1');
        try {
            $this->assertSame(0, $this->executor()->run(null, null, [], null, 'fpm-fcgi'));
        } finally {
            putenv('OMEKA_CODE_SNIPPETS_SAFE_MODE');
        }
        $this->assertSame([], $GLOBALS['integrity_runs']);
    }

    public function testStatusPresentationDoesNotChangeTrust(): void
    {
        $row = $this->repository->create($this->data());
        $this->assertSame('Unsigned', $this->service->integrityStatus($row));
        $row = $this->service->activate($row['id']);
        $this->assertSame('Valid', $this->service->integrityStatus($row));
        $row['signature'] = 'forged';
        $this->assertSame('Invalid', $this->service->integrityStatus($row));
        $service = new SnippetService($this->repository, new PhpValidator(), new SnippetSigner(false));
        $this->assertSame('Signing configuration error', $service->integrityStatus($row));
    }

    public function testVerificationExceptionAndDiagnosticFailureDoNotStopLaterValidSnippet(): void
    {
        $this->service->create($this->data());
        $this->service->create($this->data());
        $signer = $this->getMockBuilder(SnippetSigner::class)
            ->setConstructorArgs([self::KEY])->onlyMethods(['verify'])->getMock();
        $signer->method('verify')->willReturnCallback(function (array $row): bool {
            if ($row['id'] === 1) {
                throw new \RuntimeException(self::KEY);
            }
            return $this->signer->verify($row);
        });
        $repository = new class (new PdoConnection($this->pdo)) extends SnippetRepository {
            public function recordError(int $id, string $type, string $message, ?int $line): void
            {
                throw new \RuntimeException('sensitive database error');
            }
        };
        $logger = new LoggerSpy();
        $executor = new SnippetExecutor(
            $repository,
            new SafeMode(),
            new SnippetEvaluator(),
            new PhpValidator(),
            $logger,
            null,
            $signer
        );
        $this->assertSame(1, $executor->run(null, null, [], null, 'fpm-fcgi'));
        $this->assertSame(['trusted'], $GLOBALS['integrity_runs']);
        $this->assertCount(2, $logger->messages);
        $this->assertStringNotContainsString(self::KEY, implode('', $logger->messages));
        $this->assertStringNotContainsString('sensitive database error', implode('', $logger->messages));
    }

    public function testLoggingFailureDoesNotStopLaterValidSnippet(): void
    {
        $this->repository->create($this->data());
        $this->service->create($this->data());
        $logger = new class {
            public function err($message): void
            {
                throw new \RuntimeException('logger failed');
            }
        };
        $this->assertSame(1, $this->executor(null, $logger)->run(null, null, [], null, 'fpm-fcgi'));
        $this->assertSame(['trusted'], $GLOBALS['integrity_runs']);
    }

    public function testCommittedActiveRowsAlwaysHaveValidSignatures(): void
    {
        $connection = new class ($this->pdo) extends PdoConnection {
            public $commits = 0;

            public function commit(): void
            {
                $signer = new SnippetSigner('0123456789abcdef0123456789abcdef');
                foreach ($this->fetchAllAssociative('SELECT * FROM code_snippet WHERE active = 1') as $row) {
                    if (!$signer->verify($row)) {
                        throw new \RuntimeException('Attempt to commit unsigned active state.');
                    }
                }
                $this->commits++;
                parent::commit();
            }
        };
        $repository = new SnippetRepository($connection);
        $service = new SnippetService($repository, new PhpValidator(), $this->signer);
        $row = $service->create($this->data());
        $service->update($row['id'], ['code' => '$x = 2;']);
        $service->deactivate($row['id']);
        $service->activate($row['id']);
        $this->assertSame(4, $connection->commits);
    }

    private function data(): array
    {
        return ['name' => 'Trusted', 'code' => '$GLOBALS["integrity_runs"][] = "trusted";', 'active' => true];
    }

    private function executor(?SnippetEvaluator $evaluator = null, $logger = null): SnippetExecutor
    {
        return new SnippetExecutor(
            $this->repository,
            new SafeMode(),
            $evaluator ?? new SnippetEvaluator(),
            new PhpValidator(),
            $logger,
            null,
            $this->signer
        );
    }
}
