<?php

namespace Skvn\Database;

use Skvn\Base\Traits\ConstructorConfig;
use Skvn\Base\Traits\AppHolder;

class RedisConnection
{
    use ConstructorConfig;
    use AppHolder;

    private $client = null;

    public function getClient()
    {
        if (is_null($this->client)) {
            $this->client = new \Redis();
            if (!$this->client->connect($this->getConfig('host'), $this->getConfig('port'))) {
                throw new Exceptions\RedisException($this->client->getLastError());
            }
            if (!$this->client->select($this->getConfig('database'))) {
                throw new Exceptions\RedisException($this->client->getLastError());
            }
        }
        return $this->client;
    }

    public function __call($method, $args)
    {
        return $this->getClient()->$method(...$args);
    }


}