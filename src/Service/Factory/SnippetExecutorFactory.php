<?php

declare(strict_types=1);

namespace CodeSnippets\Service\Factory;

use CodeSnippets\Service\PhpValidator;
use CodeSnippets\Service\SafeMode;
use CodeSnippets\Service\SnippetEvaluator;
use CodeSnippets\Service\SnippetExecutor;
use CodeSnippets\Service\SnippetRepository;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class SnippetExecutorFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $container, $requestedName, ?array $options = null)
    {
        $logger = $container->has('Omeka\Logger') ? $container->get('Omeka\Logger') : null;
        $auth = $container->has('Omeka\AuthenticationService')
            ? $container->get('Omeka\AuthenticationService')
            : null;

        return new SnippetExecutor(
            $container->get(SnippetRepository::class),
            $container->get(SafeMode::class),
            $container->get(SnippetEvaluator::class),
            $container->get(PhpValidator::class),
            $logger,
            $auth
        );
    }
}
