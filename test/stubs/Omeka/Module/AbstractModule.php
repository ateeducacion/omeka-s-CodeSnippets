<?php

namespace Omeka\Module;

use Laminas\Mvc\MvcEvent;
use Laminas\ServiceManager\ServiceLocatorInterface;

abstract class AbstractModule
{
    protected $serviceLocator;

    public function onBootstrap(MvcEvent $event)
    {
    }

    public function setServiceLocator(ServiceLocatorInterface $serviceLocator)
    {
        $this->serviceLocator = $serviceLocator;
    }

    public function getServiceLocator()
    {
        return $this->serviceLocator;
    }
}
