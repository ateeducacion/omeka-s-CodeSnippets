<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Support;

use Psr\Container\ContainerInterface;

class FakeContainer implements ContainerInterface
{
    /** @var array<string, mixed> */
    private $services;

    /**
     * @param array<string, mixed> $services
     */
    public function __construct(array $services = [])
    {
        $this->services = $services;
    }

    public function get(string $id)
    {
        if (!$this->has($id)) {
            throw new \RuntimeException('Service not found: ' . $id);
        }
        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->services);
    }
}
