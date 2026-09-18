<?php

namespace Omeka\Api\Adapter;

use Omeka\Api\ResourceInterface;

abstract class AbstractAdapter
{
    protected $services;

    abstract public function getRepresentationClass();

    public function setServiceLocator($services)
    {
        $this->services = $services;
        return $this;
    }

    public function getServiceLocator()
    {
        return $this->services;
    }

    public function getResourceId()
    {
        return get_called_class();
    }

    public function getRepresentation(?ResourceInterface $data = null)
    {
        if (null === $data) {
            return null;
        }
        $class = $this->getRepresentationClass();
        return new $class($data, $this);
    }
}
