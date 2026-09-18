<?php

declare(strict_types=1);

namespace CodeSnippetsTest;

require dirname(__DIR__) . '/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $map = [
        'Omeka\\Module\\AbstractModule' => __DIR__ . '/stubs/Omeka/Module/AbstractModule.php',
        'Omeka\\Mvc\\Exception\\PermissionDeniedException' =>
            __DIR__ . '/stubs/Omeka/Mvc/Exception/PermissionDeniedException.php',
        'Omeka\\Stdlib\\Message' => __DIR__ . '/stubs/Omeka/Stdlib/Message.php',
        'Laminas\\Mvc\\MvcEvent' => __DIR__ . '/stubs/Laminas/Mvc/MvcEvent.php',
        'Laminas\\Mvc\\Controller\\AbstractActionController' =>
            __DIR__ . '/stubs/Laminas/Mvc/Controller/AbstractActionController.php',
        'Laminas\\View\\Model\\ViewModel' => __DIR__ . '/stubs/Laminas/View/Model/ViewModel.php',
        'Laminas\\Session\\Container' => __DIR__ . '/stubs/Laminas/Session/Container.php',
        'Laminas\\ServiceManager\\ServiceLocatorInterface' =>
            __DIR__ . '/stubs/Laminas/ServiceManager/ServiceLocatorInterface.php',
        'Omeka\\Api\\ResourceInterface' => __DIR__ . '/stubs/Omeka/Api/ResourceInterface.php',
        'Omeka\\Api\\Request' => __DIR__ . '/stubs/Omeka/Api/Request.php',
        'Omeka\\Api\\Response' => __DIR__ . '/stubs/Omeka/Api/Response.php',
        'Omeka\\Api\\Adapter\\AbstractAdapter' => __DIR__ . '/stubs/Omeka/Api/Adapter/AbstractAdapter.php',
        'Omeka\\Api\\Representation\\AbstractResourceRepresentation' =>
            __DIR__ . '/stubs/Omeka/Api/Representation/AbstractResourceRepresentation.php',
        'Omeka\\Api\\Exception\\NotFoundException' =>
            __DIR__ . '/stubs/Omeka/Api/Exception/NotFoundException.php',
        'Omeka\\Api\\Exception\\PermissionDeniedException' =>
            __DIR__ . '/stubs/Omeka/Api/Exception/PermissionDeniedException.php',
        'Omeka\\Api\\Exception\\ValidationException' =>
            __DIR__ . '/stubs/Omeka/Api/Exception/ValidationException.php',
        'Omeka\\Api\\Exception\\BadRequestException' =>
            __DIR__ . '/stubs/Omeka/Api/Exception/BadRequestException.php',
        'Interop\\Container\\ContainerInterface' =>
            __DIR__ . '/stubs/Interop/Container/ContainerInterface.php',
    ];
    if (isset($map[$class]) && is_file($map[$class])) {
        require $map[$class];
    }
});
