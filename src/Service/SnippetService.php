<?php

declare(strict_types=1);

namespace CodeSnippets\Service;

use CodeSnippets\Exception\InvalidSyntaxException;
use CodeSnippets\Exception\SnippetIntegrityException;
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

    /** @var SnippetSigner */
    private $signer;

    public function __construct(
        SnippetRepositoryInterface $repository,
        PhpValidator $validator,
        ?SnippetSigner $signer = null
    ) {
        $this->repository = $repository;
        $this->validator = $validator;
        $this->signer = $signer ?? new SnippetSigner();
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
        return $this->repository->transactional(function () use ($data) {
            return $this->signWrittenState($this->repository->create($data));
        });
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

            // Persist the same complete state whose code was validated above.
            $final = $wantsActive && $this->signer->state() !== SnippetSigner::DISABLED
                ? array_replace($existing, $data)
                : $data;
            return $this->signWrittenState($repository->update($id, $final));
        };

        return $repository->transactional($apply);
    }

    /**
     * @return array<string, mixed>
     */
    public function activate(int $id): array
    {
        return $this->update($id, ['active' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    public function deactivate(int $id): array
    {
        return $this->update($id, ['active' => false]);
    }

    private function signWrittenState(array $snippet): array
    {
        if ($this->signer->state() === SnippetSigner::DISABLED) {
            return $snippet;
        }
        try {
            $signature = $snippet['active'] ? $this->signer->sign($snippet) : null;
            return $this->repository->setSignature((int) $snippet['id'], $signature);
        } catch (\Throwable $ignored) {
            throw new SnippetIntegrityException('Snippet integrity signing failed.');
        }
    }

    /** Read-only presentation status; execution always verifies independently. */
    public function integrityStatus(array $snippet): string
    {
        if ($this->signer->state() === SnippetSigner::DISABLED) {
            return 'Signing disabled'; // @translate
        }
        if ($this->signer->state() === SnippetSigner::MISCONFIGURED) {
            return 'Signing configuration error'; // @translate
        }
        if (empty($snippet['signature'])) {
            return 'Unsigned'; // @translate
        }
        if ($this->signer->verify($snippet)) {
            return 'Valid'; // @translate
        }
        return 'Invalid'; // @translate
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
