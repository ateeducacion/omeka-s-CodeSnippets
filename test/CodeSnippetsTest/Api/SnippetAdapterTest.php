<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Api;

use CodeSnippets\Api\Adapter\SnippetAdapter;
use CodeSnippets\Api\Representation\SnippetRepresentation;
use CodeSnippets\Service\PhpValidator;
use CodeSnippets\Service\SnippetService;
use CodeSnippetsTest\Support\InMemorySnippetRepository;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Exception\PermissionDeniedException;
use Omeka\Api\Exception\ValidationException;
use Omeka\Api\Request;
use PHPUnit\Framework\TestCase;

class SnippetAdapterTest extends TestCase
{
    /** @var InMemorySnippetRepository */
    private $repository;

    /** @var SnippetAdapter */
    private $adapter;

    protected function setUp(): void
    {
        $this->repository = new InMemorySnippetRepository();
        $this->adapter = $this->makeAdapter(false);
    }

    private function makeAdapter(bool $writesEnabled): SnippetAdapter
    {
        $settings = new class ($writesEnabled) {
            /** @var bool */
            private $writesEnabled;

            public function __construct(bool $writesEnabled)
            {
                $this->writesEnabled = $writesEnabled;
            }

            public function get($key, $default = null)
            {
                return $key === SnippetAdapter::WRITE_SETTING ? $this->writesEnabled : $default;
            }
        };
        $services = new \CodeSnippetsTest\Support\ArrayServiceLocator(['Omeka\Settings' => $settings]);

        $adapter = new SnippetAdapter();
        $adapter->setServiceLocator($services);
        $adapter->setSnippetService(new SnippetService($this->repository, new PhpValidator()));
        return $adapter;
    }

    private function request(string $operation, ?int $id = null, array $content = []): Request
    {
        $request = new Request($operation, SnippetAdapter::RESOURCE_NAME);
        if ($id !== null) {
            $request->setId($id);
        }
        $request->setContent($content);
        return $request;
    }

    public function testResourceNameAndRepresentationClass(): void
    {
        $this->assertSame('code_snippets', $this->adapter->getResourceName());
        $this->assertSame(SnippetRepresentation::class, $this->adapter->getRepresentationClass());
    }

    public function testSearchReturnsEverySnippetWithATotal(): void
    {
        $this->repository->create(['name' => 'A', 'code' => '$a = 1;']);
        $this->repository->create(['name' => 'B', 'code' => '$b = 1;']);

        $response = $this->adapter->search($this->request(Request::SEARCH));

        $this->assertCount(2, $response->getContent());
        $this->assertSame(2, $response->getTotalResults());
    }

    public function testReadReturnsTheSnippet(): void
    {
        $this->repository->create(['name' => 'A', 'code' => '$a = 1;']);

        $resource = $this->adapter->read($this->request(Request::READ, 1))->getContent();

        $this->assertSame(1, $resource->getId());
        $this->assertSame('A', $resource->toArray()['name']);
    }

    public function testReadingAMissingSnippetIsANotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->adapter->read($this->request(Request::READ, 404));
    }

    public function testReadWithoutAnIdIsANotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->adapter->read($this->request(Request::READ));
    }

    /**
     * The gate is the reason this adapter can ship: an API key must not be a
     * remote code execution primitive unless the operator opted in.
     *
     * @dataProvider writeOperations
     */
    public function testWritesAreDeniedWhileTheSettingIsOff(string $operation): void
    {
        $this->repository->create(['name' => 'A', 'code' => '$a = 1;']);

        $this->expectException(PermissionDeniedException::class);
        $this->adapter->{$operation}($this->request(
            $operation,
            $operation === 'create' ? null : 1,
            ['name' => 'Pwned', 'code' => 'system("id");']
        ));
    }

    /**
     * @return array<int, array<int, string>>
     */
    public function writeOperations(): array
    {
        return [['create'], ['update'], ['delete']];
    }

    public function testWritesStayDeniedWhenTheSettingsServiceIsUnavailable(): void
    {
        $adapter = new SnippetAdapter();
        $adapter->setServiceLocator(new \CodeSnippetsTest\Support\ArrayServiceLocator([]));
        $adapter->setSnippetService(new SnippetService($this->repository, new PhpValidator()));

        $this->expectException(PermissionDeniedException::class);
        $adapter->create($this->request(Request::CREATE, null, ['name' => 'A', 'code' => '$a = 1;']));
    }

    public function testCreateStoresTheSnippetWhenWritesAreEnabled(): void
    {
        $adapter = $this->makeAdapter(true);

        $resource = $adapter->create($this->request(Request::CREATE, null, [
            'name' => 'From API',
            'code' => '$x = 1;',
            'run_scope' => 'admin',
        ]))->getContent();

        $this->assertSame('From API', $resource->toArray()['name']);
        $this->assertSame('admin', $resource->toArray()['run_scope']);
    }

    public function testCreateRejectsMissingRequiredFields(): void
    {
        $adapter = $this->makeAdapter(true);

        $this->expectException(ValidationException::class);
        $adapter->create($this->request(Request::CREATE, null, ['name' => 'No code']));
    }

    public function testCreateRejectsInvalidPhpWhenActivatingImmediately(): void
    {
        $adapter = $this->makeAdapter(true);

        $this->expectException(ValidationException::class);
        $adapter->create($this->request(Request::CREATE, null, [
            'name' => 'Broken',
            'code' => 'this is not php',
            'active' => true,
        ]));
    }

    /**
     * Columns the module maintains itself must not be settable by a client.
     */
    public function testWritesIgnoreFieldsOutsideTheWhitelist(): void
    {
        $adapter = $this->makeAdapter(true);
        $this->repository->create(['name' => 'A', 'code' => '$a = 1;']);

        $adapter->update($this->request(Request::UPDATE, 1, [
            'id' => 99,
            'name' => 'Renamed',
            'last_error_message' => 'injected',
            'last_error_type' => 'injected',
        ]));

        $stored = $this->repository->find(1);
        $this->assertSame(1, $stored['id']);
        $this->assertSame('Renamed', $stored['name']);
        $this->assertNull($stored['last_error_message']);
    }

    public function testUpdateOfAMissingSnippetIsANotFound(): void
    {
        $adapter = $this->makeAdapter(true);

        $this->expectException(NotFoundException::class);
        $adapter->update($this->request(Request::UPDATE, 404, ['name' => 'X']));
    }

    public function testDeleteRemovesTheSnippetAndReturnsIt(): void
    {
        $adapter = $this->makeAdapter(true);
        $this->repository->create(['name' => 'A', 'code' => '$a = 1;']);

        $resource = $adapter->delete($this->request(Request::DELETE, 1))->getContent();

        $this->assertSame('A', $resource->toArray()['name']);
        $this->assertNull($this->repository->find(1));
    }

    public function testRepresentationSerializesTheSnippet(): void
    {
        $this->repository->create([
            'name' => 'A',
            'description' => 'D',
            'code' => '$a = 1;',
            'run_scope' => 'front-end',
        ]);
        $resource = $this->adapter->read($this->request(Request::READ, 1))->getContent();

        $json = $this->adapter->getRepresentation($resource)->jsonSerialize();

        $this->assertSame(1, $json['o:id']);
        $this->assertSame('A', $json['o:name']);
        $this->assertSame('$a = 1;', $json['o-module-code-snippets:code']);
        $this->assertSame('front-end', $json['o-module-code-snippets:run_scope']);
        $this->assertFalse($json['o:is_active']);
        $this->assertNull($json['o-module-code-snippets:last_error']);
        $this->assertSame('o-module-code-snippets:Snippet', $this->adapter
            ->getRepresentation($resource)->getJsonLdType());
    }

    public function testRepresentationExposesTheLastRuntimeError(): void
    {
        $this->repository->create(['name' => 'A', 'code' => '$a = 1;']);
        $this->repository->recordError(1, 'RuntimeException', 'boom', 7);
        $resource = $this->adapter->read($this->request(Request::READ, 1))->getContent();

        $error = $this->adapter->getRepresentation($resource)->jsonSerialize()['o-module-code-snippets:last_error'];

        $this->assertSame('RuntimeException', $error['type']);
        $this->assertSame('boom', $error['message']);
        $this->assertSame(7, $error['line']);
    }

    public function testGetRepresentationOfNullIsNull(): void
    {
        $this->assertNull($this->adapter->getRepresentation(null));
    }
}
