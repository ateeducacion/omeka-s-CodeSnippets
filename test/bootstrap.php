<?php

declare(strict_types=1);

namespace CodeSnippetsTest;

require dirname(__DIR__) . '/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $map = [
        'Omeka\\Module\\AbstractModule' => __DIR__ . '/stubs/Omeka/Module/AbstractModule.php',
        'Laminas\\Mvc\\MvcEvent' => __DIR__ . '/stubs/Laminas/Mvc/MvcEvent.php',
        'Laminas\\ServiceManager\\ServiceLocatorInterface' =>
            __DIR__ . '/stubs/Laminas/ServiceManager/ServiceLocatorInterface.php',
    ];
    if (isset($map[$class]) && is_file($map[$class])) {
        require $map[$class];
    }
});
