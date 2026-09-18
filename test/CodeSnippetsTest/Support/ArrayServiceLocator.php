<?php

declare(strict_types=1);

namespace CodeSnippetsTest\Support;

use Laminas\ServiceManager\ServiceLocatorInterface;

class ArrayServiceLocator implements ServiceLocatorInterface
{
    /** @var array<string, mixed> */
    private $services;

    /**
     * @param array<string, mixed> $services
     */
    public function __construct(array $services)
    {
        $this->services = $services;
    }

    public function get(string $id)
    {
        if (!array_key_exists($id, $this->services)) {
            throw new \RuntimeException('Service not found: ' . $id);
        }
        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->services);
    }

    /**
     * @param array<string, mixed>|null $options
     */
    public function build($name, ?array $options = null)
    {
        return $this->get((string) $name);
    }
}
