<?php

namespace Omeka\Api;

class Request
{
    const SEARCH = 'search';
    const CREATE = 'create';
    const READ = 'read';
    const UPDATE = 'update';
    const DELETE = 'delete';

    protected $operation;
    protected $resource;
    protected $id;
    protected $content = [];
    protected $options = [];

    public function __construct($operation = null, $resource = null)
    {
        $this->operation = $operation;
        $this->resource = $resource;
    }

    public function getOperation()
    {
        return $this->operation;
    }

    public function getResource()
    {
        return $this->resource;
    }

    public function setId($id)
    {
        $this->id = $id;
        return $this;
    }

    public function getId()
    {
        return $this->id;
    }

    public function setContent(array $content)
    {
        $this->content = $content;
        return $this;
    }

    public function getContent()
    {
        return $this->content;
    }

    public function setOption($key, $value = null)
    {
        $this->options[$key] = $value;
        return $this;
    }

    public function getOption($key = null, $default = null)
    {
        if ($key === null) {
            return $this->options;
        }
        return array_key_exists($key, $this->options) ? $this->options[$key] : $default;
    }
}
