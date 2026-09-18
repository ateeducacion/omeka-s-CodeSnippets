<?php

declare(strict_types=1);

namespace CodeSnippets\Api\Adapter;

use CodeSnippets\Api\Representation\SnippetRepresentation;
use CodeSnippets\Api\SnippetResource;
use CodeSnippets\Exception\InvalidSyntaxException;
use CodeSnippets\Exception\SnippetNotFoundException;
use CodeSnippets\Service\SnippetService;
use Omeka\Api\Adapter\AbstractAdapter;
use Omeka\Api\Exception\NotFoundException;
use Omeka\Api\Exception\PermissionDeniedException;
use Omeka\Api\Exception\ValidationException;
use Omeka\Api\Request;
use Omeka\Api\Response;

/**
 * Exposes snippets on the Omeka S REST API at /api/code_snippets.
 *
 * Reads are available to any role the ACL allows (global_admin only, see
 * Module::registerAcl). Writes additionally require WRITE_SETTING, because a
 * write accepts PHP that this module later executes: Omeka passes API
 * credentials in the query string, where they reach access and proxy logs, so
 * a key must not be a remote code execution primitive unless the operator has
 * deliberately accepted that. The gate fails closed.
 */
class SnippetAdapter extends AbstractAdapter
{
    public const RESOURCE_NAME = 'code_snippets';

    public const WRITE_SETTING = 'codesnippets_api_writes';

    /**
     * Request keys accepted on write. Everything else is discarded so callers
     * cannot reach columns such as the last_error_* diagnostics or the id.
     */
    public const WRITABLE_FIELDS = ['name', 'description', 'code', 'priority', 'active', 'run_scope'];

    /** @var SnippetService|null */
    private $service;

    public function getResourceName()
    {
        return self::RESOURCE_NAME;
    }

    public function getRepresentationClass()
    {
        return SnippetRepresentation::class;
    }

    /**
     * Test seam: production resolves the service from the service locator.
     */
    public function setSnippetService(SnippetService $service): void
    {
        $this->service = $service;
    }

    public function search(Request $request)
    {
        $snippets = $this->snippetService()->findAll();
        $resources = [];
        foreach ($snippets as $snippet) {
            $resources[] = new SnippetResource($snippet);
        }

        $response = new Response($resources);
        $response->setTotalResults(count($resources));
        return $response;
    }

    public function read(Request $request)
    {
        return new Response(new SnippetResource($this->requireSnippet($request)));
    }

    public function create(Request $request)
    {
        $this->assertWritesEnabled();

        $data = $this->writableData($request->getContent());
        foreach (['name', 'code'] as $required) {
            if (!array_key_exists($required, $data)) {
                throw new ValidationException(sprintf('The "%s" field is required.', $required));
            }
        }

        return new Response(new SnippetResource($this->guard(function () use ($data) {
            return $this->snippetService()->create($data);
        })));
    }

    public function update(Request $request)
    {
        $this->assertWritesEnabled();

        $id = $this->requireId($request);
        $data = $this->writableData($request->getContent());

        return new Response(new SnippetResource($this->guard(function () use ($id, $data) {
            return $this->snippetService()->update($id, $data);
        })));
    }

    public function delete(Request $request)
    {
        $this->assertWritesEnabled();

        $snippet = $this->requireSnippet($request);
        $this->snippetService()->delete((int) $snippet['id']);
        return new Response(new SnippetResource($snippet));
    }

    /**
     * Writes stay disabled unless the operator turns them on, and any failure
     * to resolve the setting is treated as disabled.
     */
    private function assertWritesEnabled(): void
    {
        if (!$this->writesEnabled()) {
            throw new PermissionDeniedException(
                'Snippet writes over the REST API are disabled. Enable them in the module configuration.'
            );
        }
    }

    private function writesEnabled(): bool
    {
        try {
            $services = $this->getServiceLocator();
            if ($services === null || !$services->has('Omeka\Settings')) {
                return false;
            }
            return (bool) $services->get('Omeka\Settings')->get(self::WRITE_SETTING, false);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @param array<string, mixed>|null $content
     * @return array<string, mixed>
     */
    private function writableData($content): array
    {
        if (!is_array($content)) {
            return [];
        }
        return array_intersect_key($content, array_flip(self::WRITABLE_FIELDS));
    }

    private function requireId(Request $request): int
    {
        $id = $request->getId();
        if ($id === null || !is_numeric($id)) {
            throw new NotFoundException('A snippet id is required.');
        }
        return (int) $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireSnippet(Request $request): array
    {
        $id = $this->requireId($request);
        try {
            return $this->snippetService()->find($id);
        } catch (SnippetNotFoundException $e) {
            throw new NotFoundException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Translate module failures into the API's own exceptions so the endpoint
     * answers 404/422 instead of a 500.
     *
     * @return array<string, mixed>
     */
    private function guard(callable $operation): array
    {
        try {
            return $operation();
        } catch (SnippetNotFoundException $e) {
            throw new NotFoundException($e->getMessage(), 0, $e);
        } catch (InvalidSyntaxException $e) {
            throw new ValidationException($e->getMessage(), 0, $e);
        } catch (\InvalidArgumentException $e) {
            throw new ValidationException($e->getMessage(), 0, $e);
        }
    }

    private function snippetService(): SnippetService
    {
        if ($this->service === null) {
            $this->service = $this->getServiceLocator()->get(SnippetService::class);
        }
        return $this->service;
    }
}
