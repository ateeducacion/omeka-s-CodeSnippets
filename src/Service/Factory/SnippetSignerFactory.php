<?php

declare(strict_types=1);

namespace CodeSnippets\Service\Factory;

use CodeSnippets\Service\SnippetSigner;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class SnippetSignerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null)
    {
        return SnippetSigner::fromConfig($container->get('Config'));
    }
}
