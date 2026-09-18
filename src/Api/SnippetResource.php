<?php

declare(strict_types=1);

namespace CodeSnippets\Api;

use Omeka\Api\ResourceInterface;

/**
 * Omeka API resource wrapper around a snippet row.
 *
 * Snippets are stored with DBAL rather than Doctrine, so there is no entity to
 * hand to the API. Omeka only requires an identifier from the data object; the
 * representation reads the rest through toArray().
 */
class SnippetResource implements ResourceInterface
{
    /** @var array<string, mixed> */
    private $data;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * @return int|null
     */
    public function getId()
    {
        return isset($this->data['id']) ? (int) $this->data['id'] : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
