<?php

declare(strict_types=1);

namespace CodeSnippets\Service\Factory;

use CodeSnippets\Service\PhpValidator;
use CodeSnippets\Service\SnippetRepository;
use CodeSnippets\Service\SnippetService;
use CodeSnippets\Service\SnippetSigner;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class SnippetServiceFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null)
    {
        return new SnippetService(
            $container->get(SnippetRepository::class),
            $container->get(PhpValidator::class),
            $container->get(SnippetSigner::class)
        );
    }
}
