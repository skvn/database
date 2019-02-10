<?php

namespace Skvn\Database;

use Skvn\Base\Traits\ArrayOrObjectAccessImpl;
use Skvn\Base\Traits\AppHolder;
use Skvn\Base\Traits\ConstructorConfig;

class RedisDispatcher
{
    use ArrayOrObjectAccessImpl;
    use AppHolder;
    use ConstructorConfig;

    private $connections = [];

    public function connection($name = "default", $alias = null)
    {
        if (is_null($alias)) {
            $alias = $name;
        }
        if (!array_key_exists($alias, $this->connections)) {
            $this->connections[$alias] = new RedisConnection($this->config['connections'][$name]);
        }
        return $this->connections[$alias];
    }

    public function __call($method, $parameters)
    {
        return $this->connection()->$method(...$parameters);
    }

    public function get($name)
    {
        return $this->connection($name);
    }

}