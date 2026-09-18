<?php

namespace Laminas\Mvc;

class MvcEvent
{
    public const EVENT_ROUTE = 'route';
    public const EVENT_DISPATCH = 'dispatch';
    public const EVENT_BOOTSTRAP = 'bootstrap';

    public $application;
    public $request;

    public function getApplication()
    {
        return $this->application;
    }

    public function getRequest()
    {
        return $this->request;
    }
}
