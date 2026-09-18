<?php

declare(strict_types=1);

namespace CodeSnippets\Service\Factory;

use CodeSnippets\Service\SnippetRepository;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class SnippetRepositoryFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null)
    {
        return new SnippetRepository($container->get('Omeka\Connection'));
    }
}
