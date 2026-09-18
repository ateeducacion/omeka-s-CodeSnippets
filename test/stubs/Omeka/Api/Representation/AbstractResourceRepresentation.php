<?php

namespace Omeka\Api\Representation;

use Omeka\Api\Adapter\AbstractAdapter;
use Omeka\Api\ResourceInterface;

abstract class AbstractResourceRepresentation implements \JsonSerializable
{
    protected $id;
    protected $resource;
    protected $adapter;
    protected $services;

    abstract public function getJsonLd();

    abstract public function getJsonLdType();

    public function __construct(ResourceInterface $resource, $adapter)
    {
        if ($adapter instanceof AbstractAdapter) {
            $this->services = $adapter->getServiceLocator();
        }
        $this->id = $resource->getId();
        $this->adapter = $adapter;
        $this->resource = $resource;
    }

    public function id()
    {
        return $this->id;
    }

    public function jsonSerialize(): array
    {
        return $this->getJsonLd();
    }
}
