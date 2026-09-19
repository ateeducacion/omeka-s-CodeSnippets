<?php

declare(strict_types=1);

namespace CodeSnippets\Service\Factory;

use CodeSnippets\Service\SnippetImportExport;
use CodeSnippets\Service\SnippetRepository;
use CodeSnippets\Service\SnippetService;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class SnippetImportExportFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null)
    {
        return new SnippetImportExport(
            $container->get(SnippetService::class),
            $container->get(SnippetRepository::class)
        );
    }
}
