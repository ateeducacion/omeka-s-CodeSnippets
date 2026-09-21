<?php

declare(strict_types=1);

namespace CodeSnippets;

use Laminas\Router\Http\Literal;
use Laminas\Router\Http\Segment;

return [
    'code_snippets' => [
        'signing_key' => null,
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'service_manager' => [
        'invokables' => [
            Service\PhpValidator::class => Service\PhpValidator::class,
            Service\SafeMode::class => Service\SafeMode::class,
            Service\SnippetEvaluator::class => Service\SnippetEvaluator::class,
        ],
        'factories' => [
            Service\SnippetSigner::class => Service\Factory\SnippetSignerFactory::class,
            Service\SnippetRepository::class => Service\Factory\SnippetRepositoryFactory::class,
            Service\SnippetService::class => Service\Factory\SnippetServiceFactory::class,
            Service\SnippetExecutor::class => Service\Factory\SnippetExecutorFactory::class,
            Service\SnippetImportExport::class => Service\Factory\SnippetImportExportFactory::class,
        ],
    ],
    'api_adapters' => [
        'invokables' => [
            Api\Adapter\SnippetAdapter::RESOURCE_NAME => Api\Adapter\SnippetAdapter::class,
        ],
    ],
    'controllers' => [
        'factories' => [
            Controller\Admin\SnippetController::class => Controller\Factory\SnippetControllerFactory::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\SnippetForm::class => Form\SnippetForm::class,
        ],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'code-snippets' => [
                        'type' => Literal::class,
                        'options' => [
                            'route' => '/code-snippets',
                            'defaults' => [
                                '__NAMESPACE__' => 'CodeSnippets\Controller\Admin',
                                'controller' => Controller\Admin\SnippetController::class,
                                'action' => 'index',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'add' => [
                                'type' => Literal::class,
                                'options' => [
                                    'route' => '/add',
                                    'defaults' => [
                                        'action' => 'add',
                                    ],
                                ],
                            ],
                            'edit' => [
                                'type' => Segment::class,
                                'options' => [
                                    'route' => '/:id/edit',
                                    'constraints' => [
                                        'id' => '\d+',
                                    ],
                                    'defaults' => [
                                        'action' => 'edit',
                                    ],
                                ],
                            ],
                            'activate' => [
                                'type' => Segment::class,
                                'options' => [
                                    'route' => '/:id/activate',
                                    'constraints' => [
                                        'id' => '\d+',
                                    ],
                                    'defaults' => [
                                        'action' => 'activate',
                                    ],
                                ],
                            ],
                            'deactivate' => [
                                'type' => Segment::class,
                                'options' => [
                                    'route' => '/:id/deactivate',
                                    'constraints' => [
                                        'id' => '\d+',
                                    ],
                                    'defaults' => [
                                        'action' => 'deactivate',
                                    ],
                                ],
                            ],
                            'delete' => [
                                'type' => Segment::class,
                                'options' => [
                                    'route' => '/:id/delete',
                                    'constraints' => [
                                        'id' => '\d+',
                                    ],
                                    'defaults' => [
                                        'action' => 'delete',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            [
                'label' => 'Code Snippets', // @translate
                'route' => 'admin/code-snippets',
                'resource' => Controller\Admin\SnippetController::class,
                'privilege' => 'index',
            ],
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
];
