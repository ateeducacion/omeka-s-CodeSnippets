<?php

declare(strict_types=1);

namespace CodeSnippets\Service;

use CodeSnippets\Db\Schema;
use CodeSnippets\Exception\SnippetNotFoundException;

/**
 * DBAL repository for `code_snippet`.
 *
 * Speaks the Doctrine DBAL 2.13 API exposed as `Omeka\Connection`. Tests may
 * supply a compatible object (PDO adapter) with the same method names.
 */
class SnippetRepository implements SnippetRepositoryInterface
{
    public const DEFAULT_PRIORITY = 10;

    /** @var object */
    private $connection;

    /**
     * @param object $connection Doctrine\DBAL\Connection or compatible test double
     */
    public function __construct($connection)
    {
        $this->connection = $connection;
    }

    public function find(int $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM `' . Schema::TABLE . '` WHERE id = ?',
            [$id]
        );
        if (!$row) {
            return null;
        }
        return $this->hydrate($row);
    }

    public function findAll(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM `' . Schema::TABLE . '` ORDER BY priority ASC, id ASC'
        );
        $snippets = [];
        foreach ($rows as $row) {
            $snippets[] = $this->hydrate($row);
        }
        return $snippets;
    }

    public function findActiveOrdered(?string $requestScope = null): array
    {
        if ($requestScope === null) {
            $rows = $this->connection->fetchAllAssociative(
                Schema::selectActiveOrderedSql(),
                [1]
            );
        } else {
            $rows = $this->connection->fetchAllAssociative(
                Schema::selectActiveOrderedForScopeSql(),
                [1, SnippetScope::GLOBAL, SnippetScope::normalize($requestScope)]
            );
        }
        $snippets = [];
        foreach ($rows as $row) {
            $snippets[] = $this->hydrate($row);
        }
        return $snippets;
    }

    public function create(array $data): array
    {
        $now = $this->now();
        $this->connection->insert(Schema::TABLE, [
            'name' => $this->normalizeName($data['name'] ?? ''),
            'description' => $this->normalizeDescription($data['description'] ?? null),
            'code' => (string) ($data['code'] ?? ''),
            'priority' => $this->normalizePriority($data['priority'] ?? self::DEFAULT_PRIORITY),
            'active' => !empty($data['active']) ? 1 : 0,
            'run_scope' => SnippetScope::normalize($data['run_scope'] ?? SnippetScope::DEFAULT),
            'created' => $now,
            'modified' => $now,
            'last_error_type' => null,
            'last_error_message' => null,
            'last_error_line' => null,
            'last_error_at' => null,
        ]);
        $id = (int) $this->connection->lastInsertId();
        $created = $this->find($id);
        if ($created === null) {
            throw new \RuntimeException('Failed to load snippet after insert.');
        }
        return $created;
    }

    public function update(int $id, array $data): array
    {
        $existing = $this->require($id);
        $fields = [
            'modified' => $this->now(),
        ];
        if (array_key_exists('name', $data)) {
            $fields['name'] = $this->normalizeName($data['name']);
        }
        if (array_key_exists('description', $data)) {
            $fields['description'] = $this->normalizeDescription($data['description']);
        }
        if (array_key_exists('code', $data)) {
            $fields['code'] = (string) $data['code'];
        }
        if (array_key_exists('priority', $data)) {
            $fields['priority'] = $this->normalizePriority($data['priority']);
        }
        if (array_key_exists('active', $data)) {
            $fields['active'] = $data['active'] ? 1 : 0;
        }
        if (array_key_exists('run_scope', $data)) {
            $fields['run_scope'] = SnippetScope::normalize($data['run_scope']);
        }

        $this->connection->update(Schema::TABLE, $fields, ['id' => $id]);
        $updated = $this->find($id);
        return $updated !== null ? $updated : $existing;
    }

    public function delete(int $id): void
    {
        $this->require($id);
        $this->connection->delete(Schema::TABLE, ['id' => $id]);
    }

    public function activate(int $id): array
    {
        return $this->update($id, ['active' => true]);
    }

    public function deactivate(int $id): array
    {
        return $this->update($id, ['active' => false]);
    }

    public function recordError(int $id, string $type, string $message, ?int $line): void
    {
        $this->require($id);
        $this->connection->update(Schema::TABLE, [
            'last_error_type' => $this->truncate($type, 255),
            'last_error_message' => $message,
            'last_error_line' => $line,
            'last_error_at' => $this->now(),
            'modified' => $this->now(),
        ], ['id' => $id]);
    }

    public function setSignature(int $id, ?string $signature): array
    {
        $this->require($id);
        $this->connection->update(Schema::TABLE, ['signature' => $signature], ['id' => $id]);
        return $this->require($id);
    }

    /**
     * @param callable $callback
     * @return mixed
     */
    public function transactional(callable $callback)
    {
        $this->connection->beginTransaction();
        try {
            $result = $callback();
            $this->connection->commit();
            return $result;
        } catch (\Throwable $exception) {
            if (method_exists($this->connection, 'isTransactionActive')) {
                if ($this->connection->isTransactionActive()) {
                    $this->connection->rollBack();
                }
            } else {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function require(int $id): array
    {
        $snippet = $this->find($id);
        if ($snippet === null) {
            throw new SnippetNotFoundException(sprintf('Snippet #%d was not found.', $id));
        }
        return $snippet;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $description = $row['description'] ?? null;
        $errorType = $row['last_error_type'] ?? null;
        $errorMessage = $row['last_error_message'] ?? null;
        $errorLine = $row['last_error_line'] ?? null;
        $errorAt = $row['last_error_at'] ?? null;

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'description' => $description !== null ? (string) $description : null,
            'code' => (string) ($row['code'] ?? ''),
            'priority' => (int) ($row['priority'] ?? self::DEFAULT_PRIORITY),
            'active' => (bool) ($row['active'] ?? true),
            'signature' => $row['signature'] ?? null,
            'run_scope' => (string) ($row['run_scope'] ?? SnippetScope::DEFAULT),
            'created' => (string) ($row['created'] ?? ''),
            'modified' => (string) ($row['modified'] ?? ''),
            'last_error_type' => $errorType !== null ? (string) $errorType : null,
            'last_error_message' => $errorMessage !== null ? (string) $errorMessage : null,
            'last_error_line' => $errorLine !== null && $errorLine !== ''
                ? (int) $errorLine
                : null,
            'last_error_at' => $errorAt !== null ? (string) $errorAt : null,
        ];
    }

    private function normalizeName($name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            throw new \InvalidArgumentException('Snippet name is required.');
        }
        if (strlen($name) > 255) {
            $name = substr($name, 0, 255);
        }
        return $name;
    }

    private function normalizeDescription($description): ?string
    {
        if ($description === null) {
            return null;
        }
        $description = (string) $description;
        return $description === '' ? null : $description;
    }

    private function normalizePriority($priority): int
    {
        if ($priority === null || $priority === '') {
            return self::DEFAULT_PRIORITY;
        }
        if (is_int($priority)) {
            return $priority;
        }
        if (is_string($priority) && preg_match('/^-?\d+$/', $priority)) {
            return (int) $priority;
        }
        if (is_numeric($priority)) {
            return (int) $priority;
        }
        throw new \InvalidArgumentException('Priority must be an integer.');
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private function truncate(string $value, int $length): string
    {
        if (strlen($value) <= $length) {
            return $value;
        }
        return substr($value, 0, $length);
    }
}
