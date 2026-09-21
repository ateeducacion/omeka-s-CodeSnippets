<?php

declare(strict_types=1);

namespace CodeSnippets\Service;

interface SnippetRepositoryInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array;

    /**
     * Active snippets ordered by priority ASC, id ASC.
     *
     * When $requestScope is set, only `global` snippets and snippets whose
     * run_scope matches that request are returned.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findActiveOrdered(?string $requestScope = null): array;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array;

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(int $id, array $data): array;

    public function delete(int $id): void;

    /**
     * @return array<string, mixed>
     */
    public function activate(int $id): array;

    /**
     * @return array<string, mixed>
     */
    public function deactivate(int $id): array;

    /** Internal trust metadata; never part of user-writable data. */
    public function setSignature(int $id, ?string $signature): array;

    public function recordError(int $id, string $type, string $message, ?int $line): void;

    /**
     * Execute a callback within a database transaction.
     *
     * @param callable $callback
     * @return mixed
     */
    public function transactional(callable $callback);
}
