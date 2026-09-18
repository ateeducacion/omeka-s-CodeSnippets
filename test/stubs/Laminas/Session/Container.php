<?php

namespace Laminas\Session;

/**
 * In-memory stand-in for laminas-session so CSRF hashes work in unit tests.
 */
class Container
{
    /** @var array<string, array<string, mixed>> */
    private static $store = [];

    /** @var string */
    private $name;

    public function __construct($name = 'Default')
    {
        $this->name = (string) $name;
        if (!isset(self::$store[$this->name])) {
            self::$store[$this->name] = [];
        }
    }

    public function __get($key)
    {
        return self::$store[$this->name][$key] ?? null;
    }

    public function __set($key, $value)
    {
        if (is_array($value)) {
            $value = new \ArrayObject($value);
        }
        self::$store[$this->name][$key] = $value;
    }

    public function __isset($key)
    {
        return isset(self::$store[$this->name][$key]);
    }

    public function setExpirationSeconds($seconds)
    {
    }

    public static function reset()
    {
        self::$store = [];
    }
}
