<?php

declare(strict_types=1);

namespace CodeSnippets\Service;

use CodeSnippets\Exception\InvalidSyntaxException;
use CodeSnippets\Exception\SnippetNotFoundException;

/**
 * Application service for snippet writes.
 *
 * Activation and "save while remaining active" require a passing syntax check
 * and run inside a transaction so invalid code cannot be stored as active.
 */
class SnippetService
{
    /** @var SnippetRepositoryInterface */
    private $repository;

    /** @var PhpValidator */
    private $validator;

    public function __construct(SnippetRepositoryInterface $repository, PhpValidator $validator)
    {
        $this->repository = $repository;
        $this->validator = $validator;
    }

    /**
     * @return array<string, mixed>
     */
    public function find(int $id): array
    {
        $snippet = $this->repository->find($id);
        if ($snippet === null) {
            throw new SnippetNotFoundException(sprintf('Snippet #%d was not found.', $id));
        }
        return $snippet;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        return $this->repository->findAll();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $data = $this->normalizeInput($data);
        if (!empty($data['active'])) {
            $this->assertValidSyntax($data['code']);
        }
        $data['code'] = $this->validator->normalize($data['code']);
        return $this->repository->create($data);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(int $id, array $data): array
    {
        $data = $this->normalizeInput($data);
        $repository = $this->repository;

        $apply = function () use ($repository, $id, $data) {
            $existing = $repository->find($id);
            if ($existing === null) {
                throw new SnippetNotFoundException(sprintf('Snippet #%d was not found.', $id));
            }

            $code = array_key_exists('code', $data) ? $data['code'] : $existing['code'];
            $wantsActive = array_key_exists('active', $data) ? (bool) $data['active'] : (bool) $existing['active'];

            if ($wantsActive) {
                $this->assertValidSyntax($code);
            }

            if (array_key_exists('code', $data)) {
                $data['code'] = $this->validator->normalize($data['code']);
            }

            return $repository->update($id, $data);
        };

        if ($repository instanceof SnippetRepository) {
            return $repository->transactional($apply);
        }

        return $apply();
    }

    /**
     * @return array<string, mixed>
     */
    public function activate(int $id): array
    {
        $snippet = $this->find($id);
        $this->assertValidSyntax($snippet['code']);
        return $this->repository->activate($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function deactivate(int $id): array
    {
        return $this->repository->deactivate($id);
    }

    public function delete(int $id): void
    {
        $this->repository->delete($id);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeInput(array $data): array
    {
        if (array_key_exists('name', $data)) {
            $data['name'] = trim((string) $data['name']);
        }
        if (array_key_exists('code', $data)) {
            $data['code'] = (string) $data['code'];
        }
        if (array_key_exists('active', $data)) {
            $data['active'] = $this->toBool($data['active']);
        }
        if (array_key_exists('priority', $data) && $data['priority'] !== '' && $data['priority'] !== null) {
            if (!is_int($data['priority']) && !preg_match('/^-?\d+$/', (string) $data['priority'])) {
                throw new \InvalidArgumentException('Priority must be an integer.');
            }
            $data['priority'] = (int) $data['priority'];
        }
        if (array_key_exists('run_scope', $data)) {
            $data['run_scope'] = SnippetScope::normalize($data['run_scope']);
        }
        return $data;
    }

    private function assertValidSyntax(string $code): void
    {
        $result = $this->validator->validate($code);
        if (!$result->isValid()) {
            throw new InvalidSyntaxException(
                $result->getMessage() !== null ? $result->getMessage() : 'Invalid PHP syntax.',
                $result->getLine()
            );
        }
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
