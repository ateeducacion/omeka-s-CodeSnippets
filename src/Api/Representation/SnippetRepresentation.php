<?php

declare(strict_types=1);

namespace CodeSnippets\Api\Representation;

use Omeka\Api\Representation\AbstractResourceRepresentation;

/**
 * JSON-LD representation of a snippet.
 *
 * Only global_admin reaches this class: Omeka's API manager checks the ACL
 * before the adapter runs, and the adapter is allowed for global_admin alone.
 * The stored PHP is therefore included, matching what the admin UI shows.
 */
class SnippetRepresentation extends AbstractResourceRepresentation
{
    public function getJsonLdType()
    {
        return 'o-module-code-snippets:Snippet';
    }

    /**
     * @return array<string, mixed>
     */
    public function getJsonLd()
    {
        $data = $this->resource->toArray();

        return [
            'o:id' => $this->id(),
            'o:name' => isset($data['name']) ? (string) $data['name'] : null,
            'o:description' => isset($data['description']) ? (string) $data['description'] : null,
            'o-module-code-snippets:code' => isset($data['code']) ? (string) $data['code'] : null,
            'o-module-code-snippets:priority' => isset($data['priority']) ? (int) $data['priority'] : null,
            'o-module-code-snippets:run_scope' => isset($data['run_scope'])
                ? (string) $data['run_scope']
                : null,
            'o:is_active' => !empty($data['active']),
            'o:created' => isset($data['created']) ? (string) $data['created'] : null,
            'o:modified' => isset($data['modified']) ? (string) $data['modified'] : null,
            'o-module-code-snippets:last_error' => $this->lastError($data),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function lastError(array $data): ?array
    {
        if (empty($data['last_error_message'])) {
            return null;
        }

        return [
            'type' => isset($data['last_error_type']) ? (string) $data['last_error_type'] : null,
            'message' => (string) $data['last_error_message'],
            'line' => isset($data['last_error_line']) && $data['last_error_line'] !== null
                ? (int) $data['last_error_line']
                : null,
            'at' => isset($data['last_error_at']) ? (string) $data['last_error_at'] : null,
        ];
    }
}
