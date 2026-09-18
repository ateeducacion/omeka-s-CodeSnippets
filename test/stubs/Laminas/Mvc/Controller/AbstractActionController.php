<?php

namespace Laminas\Mvc\Controller;

class AbstractActionController
{
    public $request;
    public $response;

    /** @var array<string, mixed> */
    public $pluginMap = [];

    public function getRequest()
    {
        return $this->request;
    }

    public function getResponse()
    {
        return $this->response;
    }

    public function __call($name, $arguments)
    {
        if (!array_key_exists($name, $this->pluginMap)) {
            throw new \BadMethodCallException('Plugin not registered: ' . $name);
        }
        $plugin = $this->pluginMap[$name];
        if ($plugin instanceof \Closure) {
            return $arguments === [] ? $plugin() : $plugin(...$arguments);
        }
        if (is_object($plugin) && $arguments === []) {
            return $plugin;
        }
        if (is_callable($plugin)) {
            return call_user_func_array($plugin, $arguments);
        }
        return $plugin;
    }
}
