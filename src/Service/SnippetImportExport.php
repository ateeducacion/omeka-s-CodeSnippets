<?php

declare(strict_types=1);

namespace CodeSnippets\Service;

use CodeSnippets\Exception\InvalidImportException;
use CodeSnippets\Exception\SnippetNotFoundException;

/**
 * Service for exporting and importing portable snippet configurations.
 *
 * Export format is a versioned envelope containing portable snippet definitions:
 * name, description, code, priority, run_scope, and active status.
 * Internal database identifiers, timestamps, and error logs are excluded.
 *
 * Import goes through SnippetService so normalization, PHP syntax validation,
 * and persistence rules are consistently enforced.
 */
class SnippetImportExport
{
    public const FORMAT = 'omeka-s-code-snippets';
    public const CURRENT_VERSION = 1;
    public const SUPPORTED_VERSIONS = [1];

    /** @var SnippetService */
    private $snippetService;

    /** @var SnippetRepositoryInterface */
    private $repository;

    public function __construct(
        SnippetService $snippetService,
        SnippetRepositoryInterface $repository
    ) {
        $this->snippetService = $snippetService;
        $this->repository = $repository;
    }

    /**
     * Export all snippets in a versioned envelope document.
     *
     * @return array<string, mixed>
     */
    public function exportAll(): array
    {
        $snippets = $this->snippetService->findAll();
        $portable = [];
        foreach ($snippets as $snippet) {
            $portable[] = $this->toPortable($snippet);
        }

        return [
            'format' => self::FORMAT,
            'version' => self::CURRENT_VERSION,
            'snippets' => $portable,
        ];
    }

    /**
     * Export a single snippet by ID.
     *
     * Returns the portable representation of the snippet. If $asEnvelope is true,
     * wraps it in a versioned envelope document.
     *
     * @param int $id Snippet database ID
     * @param bool $asEnvelope Whether to wrap in an envelope
     * @return array<string, mixed>
     * @throws SnippetNotFoundException If snippet not found
     */
    public function export(int $id, bool $asEnvelope = false): array
    {
        $snippet = $this->snippetService->find($id);
        $portable = $this->toPortable($snippet);

        if ($asEnvelope) {
            return [
                'format' => self::FORMAT,
                'version' => self::CURRENT_VERSION,
                'snippets' => [$portable],
            ];
        }

        return $portable;
    }

    /**
     * Convenience helper to export as JSON string.
     *
     * When $id is provided, exports that single snippet.
     * When $id is null, exports all snippets in an envelope document.
     *
     * @param int|null $id Optional snippet ID
     * @return string Formatted JSON
     * @throws \JsonException On encoding error
     */
    public function exportJson(?int $id = null): string
    {
        $data = $id !== null ? $this->export($id) : $this->exportAll();

        return json_encode(
            $data,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Import a single snippet or a single-snippet document.
     *
     * By default, creates a new snippet through SnippetService.
     * If $data is a document containing multiple snippets, an InvalidImportException
     * is thrown directing the caller to importMany().
     *
     * @param array<string, mixed> $data Single snippet definition or envelope document
     * @param array<string, mixed> $options Reserved for future import strategies
     * @return array<string, mixed> The created snippet row from SnippetService
     * @throws InvalidImportException On validation failure
     */
    public function import(array $data, array $options = []): array
    {
        if ($this->isDocument($data)) {
            $this->validateDocument($data);
            $snippets = $data['snippets'];

            if (count($snippets) === 0) {
                return [];
            }

            if (count($snippets) === 1) {
                $snippet = $snippets[0];
                if (!is_array($snippet)) {
                    throw new InvalidImportException('Snippet must be an array.');
                }
                $this->validateSnippet($snippet);
                $prepared = $this->prepareSnippetData($snippet);
                return $this->snippetService->create($prepared);
            }

            throw new InvalidImportException(sprintf(
                'Document contains %d snippets. Use importMany() to import multiple snippets.',
                count($snippets)
            ));
        }

        if ($this->isSnippetList($data)) {
            if (count($data) === 0) {
                return [];
            }

            if (count($data) === 1) {
                $snippet = $data[0];
                if (!is_array($snippet)) {
                    throw new InvalidImportException('Snippet must be an array.');
                }
                $this->validateSnippet($snippet);
                $prepared = $this->prepareSnippetData($snippet);
                return $this->snippetService->create($prepared);
            }

            throw new InvalidImportException(sprintf(
                'List contains %d snippets. Use importMany() to import multiple snippets.',
                count($data)
            ));
        }

        $this->validateSnippet($data);
        $prepared = $this->prepareSnippetData($data);
        return $this->snippetService->create($prepared);
    }

    /**
     * Import multiple snippets from an envelope document or a list of snippets.
     *
     * Pre-validates all snippets before persisting. Persists transactionally
     * so either all snippets are created or none are persisted if an error occurs.
     *
     * @param array<mixed> $data Document envelope or array of snippets
     * @param array<string, mixed> $options Reserved for future import strategies
     * @return array<int, array<string, mixed>> Created snippet rows
     * @throws InvalidImportException On validation failure
     */
    public function importMany(array $data, array $options = []): array
    {
        if ($this->isDocument($data)) {
            $this->validateDocument($data);
            $snippets = $data['snippets'];
        } elseif ($this->isSnippetList($data)) {
            $snippets = $data;
        } else {
            $this->validateSnippet($data);
            $snippets = [$data];
        }

        foreach ($snippets as $index => $snippet) {
            if (!is_array($snippet)) {
                throw new InvalidImportException(sprintf(
                    'Snippet at index %s must be an array.',
                    (string) $index
                ));
            }
            $this->validateSnippet($snippet);
        }

        if (empty($snippets)) {
            return [];
        }

        $execute = function () use ($snippets) {
            $created = [];
            foreach ($snippets as $snippet) {
                $prepared = $this->prepareSnippetData($snippet);
                $created[] = $this->snippetService->create($prepared);
            }
            return $created;
        };

        if (method_exists($this->repository, 'transactional')) {
            return $this->repository->transactional($execute);
        }

        return $execute();
    }

    /**
     * Convenience helper to import from a JSON string.
     *
     * @param string $json JSON string
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>> List of created snippets
     * @throws InvalidImportException On JSON parse or validation error
     */
    public function importJson(string $json, array $options = []): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidImportException(
                sprintf('Invalid JSON input: %s', $e->getMessage()),
                (int) $e->getCode(),
                $e
            );
        }

        if (!is_array($data)) {
            throw new InvalidImportException('Decoded JSON must be an array or object.');
        }

        return $this->importMany($data, $options);
    }

    /**
     * Convert an internal snippet row to its portable representation.
     *
     * @param array<string, mixed> $snippet
     * @return array<string, mixed>
     */
    private function toPortable(array $snippet): array
    {
        $description = $snippet['description'] ?? null;

        return [
            'name' => (string) $snippet['name'],
            'description' => $description !== null && $description !== '' ? (string) $description : null,
            'code' => (string) $snippet['code'],
            'priority' => (int) ($snippet['priority'] ?? SnippetRepository::DEFAULT_PRIORITY),
            'run_scope' => SnippetScope::normalize($snippet['run_scope'] ?? SnippetScope::DEFAULT),
            'active' => !empty($snippet['active']),
        ];
    }

    /**
     * Normalize and prepare portable snippet data for SnippetService::create().
     *
     * @param array<string, mixed> $snippet
     * @return array<string, mixed>
     */
    private function prepareSnippetData(array $snippet): array
    {
        $active = false;
        if (array_key_exists('active', $snippet) && $snippet['active'] !== null) {
            $active = $this->toBool($snippet['active']);
        }

        $priority = SnippetRepository::DEFAULT_PRIORITY;
        if (array_key_exists('priority', $snippet) && $snippet['priority'] !== null && $snippet['priority'] !== '') {
            $priority = (int) $snippet['priority'];
        }

        $runScope = SnippetScope::DEFAULT;
        if (array_key_exists('run_scope', $snippet) && $snippet['run_scope'] !== null && $snippet['run_scope'] !== '') {
            $runScope = (string) $snippet['run_scope'];
        }

        $description = null;
        if (array_key_exists('description', $snippet) && $snippet['description'] !== null) {
            $desc = trim((string) $snippet['description']);
            $description = $desc !== '' ? $desc : null;
        }

        return [
            'name' => trim((string) $snippet['name']),
            'description' => $description,
            'code' => (string) $snippet['code'],
            'priority' => $priority,
            'run_scope' => $runScope,
            'active' => $active,
        ];
    }

    /**
     * Validate an export document envelope.
     *
     * Unknown top-level fields are ignored deliberately to maintain forward
     * compatibility with future schema versions and external tooling metadata.
     *
     * @param array<string, mixed> $data
     * @throws InvalidImportException
     */
    private function validateDocument(array $data): void
    {
        if (!array_key_exists('format', $data) || !is_string($data['format']) || $data['format'] !== self::FORMAT) {
            $actualFormat = is_scalar($data['format'] ?? null)
                ? (string) $data['format']
                : gettype($data['format'] ?? null);
            throw new InvalidImportException(sprintf(
                'Invalid or missing format identifier "%s". Expected "%s".',
                $actualFormat,
                self::FORMAT
            ));
        }

        if (!array_key_exists('version', $data)
            || !is_int($data['version'])
            || !in_array($data['version'], self::SUPPORTED_VERSIONS, true)
        ) {
            $versionStr = is_scalar($data['version'] ?? null)
                ? (string) $data['version']
                : gettype($data['version'] ?? null);
            throw new InvalidImportException(sprintf(
                'Unsupported format version "%s". Supported versions: %s.',
                $versionStr,
                implode(', ', self::SUPPORTED_VERSIONS)
            ));
        }

        if (!array_key_exists('snippets', $data) || !is_array($data['snippets'])) {
            throw new InvalidImportException('The "snippets" field is required and must be an array.');
        }
    }

    /**
     * Validate a single snippet definition.
     *
     * Unknown snippet-level fields are ignored deliberately to ensure forward
     * compatibility.
     *
     * @param array<string, mixed> $snippet
     * @throws InvalidImportException
     */
    private function validateSnippet(array $snippet): void
    {
        if (!array_key_exists('name', $snippet)
            || !is_string($snippet['name'])
            || trim($snippet['name']) === ''
        ) {
            throw new InvalidImportException('Snippet "name" is required and cannot be empty.');
        }

        if (!array_key_exists('code', $snippet) || !is_string($snippet['code'])) {
            throw new InvalidImportException('Snippet "code" is required and must be a string.');
        }

        if (array_key_exists('priority', $snippet)
            && $snippet['priority'] !== null
            && $snippet['priority'] !== ''
        ) {
            if (!is_int($snippet['priority'])
                && (!is_string($snippet['priority']) || !preg_match('/^-?\d+$/', $snippet['priority']))
            ) {
                throw new InvalidImportException('Snippet "priority" must be an integer.');
            }
        }

        if (array_key_exists('run_scope', $snippet)
            && $snippet['run_scope'] !== null
            && $snippet['run_scope'] !== ''
        ) {
            if (!is_string($snippet['run_scope'])
                || !in_array($snippet['run_scope'], SnippetScope::all(), true)
            ) {
                $scopeStr = is_scalar($snippet['run_scope'])
                    ? (string) $snippet['run_scope']
                    : gettype($snippet['run_scope']);
                throw new InvalidImportException(sprintf(
                    'Invalid run_scope "%s". Valid scopes are: %s.',
                    $scopeStr,
                    implode(', ', SnippetScope::all())
                ));
            }
        }

        if (array_key_exists('active', $snippet) && $snippet['active'] !== null) {
            if (!is_bool($snippet['active'])
                && !in_array($snippet['active'], [0, 1, '0', '1', 'true', 'false'], true)
            ) {
                throw new InvalidImportException('Snippet "active" must be a boolean.');
            }
        }

        if (array_key_exists('description', $snippet) && $snippet['description'] !== null) {
            if (!is_string($snippet['description'])) {
                throw new InvalidImportException('Snippet "description" must be a string or null.');
            }
        }
    }

    /**
     * @param array<mixed> $data
     */
    private function isDocument(array $data): bool
    {
        return array_key_exists('format', $data)
            || array_key_exists('snippets', $data)
            || array_key_exists('version', $data);
    }

    /**
     * @param array<mixed> $data
     */
    private function isSnippetList(array $data): bool
    {
        if ($data === []) {
            return true;
        }

        return array_keys($data) === range(0, count($data) - 1);
    }

    /**
     * @param mixed $value
     */
    private function toBool($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return $value === 1 || $value === '1' || $value === 'true' || $value === 'on';
    }
}
