<?php

declare(strict_types=1);

namespace CodeSnippets\Controller\Factory;

use CodeSnippets\Controller\Admin\SnippetController;
use CodeSnippets\Service\ActionCsrf;
use CodeSnippets\Service\SafeMode;
use CodeSnippets\Service\SnippetService;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class SnippetControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null)
    {
        return new SnippetController(
            $container->get(SnippetService::class),
            $container->get(SafeMode::class),
            new ActionCsrf()
        );
    }
}
