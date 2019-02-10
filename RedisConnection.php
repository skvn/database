<?php

namespace Skvn\Database;

use Skvn\Base\Traits\ConstructorConfig;

class RedisConnection
{
    use ConstructorConfig;

    private $client = null;

    public function getClient()
    {
        if (is_null($this->client)) {
            $this->client = new \Redis();
            if (!$this->client->pconnect($this->getConfig('host'), $this->getConfig('port'))) {
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