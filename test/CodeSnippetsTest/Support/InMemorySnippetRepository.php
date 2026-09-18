<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Support;

use CodeSnippets\Exception\SnippetNotFoundException;
use CodeSnippets\Service\SnippetRepository;
use CodeSnippets\Service\SnippetRepositoryInterface;
use CodeSnippets\Service\SnippetScope;

class InMemorySnippetRepository implements SnippetRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    private $snippets = [];

    /** @var int */
    private $nextId = 1;

    /** @var int */
    public $findActiveCalls = 0;

    public function find(int $id): ?array
    {
        return isset($this->snippets[$id]) ? $this->snippets[$id] : null;
    }

    public function findAll(): array
    {
        $rows = array_values($this->snippets);
        usort($rows, static function (array $a, array $b) {
            if ($a['priority'] === $b['priority']) {
                return $a['id'] <=> $b['id'];
            }
            return $a['priority'] <=> $b['priority'];
        });
        return $rows;
    }

    public function findActiveOrdered(?string $requestScope = null): array
    {
        $this->findActiveCalls++;
        $rows = [];
        foreach ($this->snippets as $snippet) {
            if (!$snippet['active']) {
                continue;
            }
            $scope = $snippet['run_scope'] ?? SnippetScope::DEFAULT;
            if ($requestScope !== null && !SnippetScope::matches($scope, $requestScope)) {
                continue;
            }
            $rows[] = $snippet;
        }
        usort($rows, static function (array $a, array $b) {
            if ($a['priority'] === $b['priority']) {
                return $a['id'] <=> $b['id'];
            }
            return $a['priority'] <=> $b['priority'];
        });
        return $rows;
    }

    public function create(array $data): array
    {
        $id = $this->nextId++;
        $now = gmdate('Y-m-d H:i:s');
        $snippet = [
            'id' => $id,
            'name' => (string) $data['name'],
            'description' => $data['description'] ?? null,
            'code' => (string) $data['code'],
            'priority' => isset($data['priority']) ? (int) $data['priority'] : SnippetRepository::DEFAULT_PRIORITY,
            'active' => !empty($data['active']),
            'run_scope' => SnippetScope::normalize($data['run_scope'] ?? SnippetScope::DEFAULT),
            'created' => $now,
            'modified' => $now,
            'last_error_type' => null,
            'last_error_message' => null,
            'last_error_line' => null,
            'last_error_at' => null,
        ];
        $this->snippets[$id] = $snippet;
        return $snippet;
    }

    public function update(int $id, array $data): array
    {
        $existing = $this->require($id);
        foreach (['name', 'description', 'code', 'priority', 'active', 'run_scope'] as $field) {
            if (array_key_exists($field, $data)) {
                $existing[$field] = $field === 'priority' ? (int) $data[$field] : $data[$field];
                if ($field === 'active') {
                    $existing[$field] = (bool) $data[$field];
                }
                if ($field === 'run_scope') {
                    $existing[$field] = SnippetScope::normalize($data[$field]);
                }
            }
        }
        $existing['modified'] = gmdate('Y-m-d H:i:s');
        $this->snippets[$id] = $existing;
        return $existing;
    }

    public function delete(int $id): void
    {
        $this->require($id);
        unset($this->snippets[$id]);
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
        $existing = $this->require($id);
        $existing['last_error_type'] = $type;
        $existing['last_error_message'] = $message;
        $existing['last_error_line'] = $line;
        $existing['last_error_at'] = gmdate('Y-m-d H:i:s');
        $this->snippets[$id] = $existing;
    }

    /**
     * @return array<string, mixed>
     */
    private function require(int $id): array
    {
        if (!isset($this->snippets[$id])) {
            throw new SnippetNotFoundException(sprintf('Snippet #%d was not found.', $id));
        }
        return $this->snippets[$id];
    }
}
