<?php

namespace Skvn\Database;

use PDO;
use PDOException;
use Skvn\Base\Container;
use Skvn\Base\Traits\ArrayOrObjectAccessImpl;

class DatabaseDispatcher
{
    use ArrayOrObjectAccessImpl;

    private $connections = [];
    private $container = null;
    private $config = [];

    function __construct($config = [])
    {
        $this->container = Container :: getInstance();
        $this->config = $config;
    }

    function connection($name = "default", $alias = null)
    {
        if (is_null($alias)) {
            $alias = $name;
        }
        if (!isset($this->connections[$alias])) {
            $config = $this->config['connections'][$name] ?? [];
            if (!$this->validateConfig($config)) {
                throw new Exceptions\ConfigException('Configuration for connection ' . $name . ' is empty or invalid');
            }
            $options = [
                PDO::ATTR_CASE => PDO::CASE_NATURAL,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                //PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::ATTR_EMULATE_PREPARES => false
            ];
            $options = ($config['options'] ?? []) + $options;
            try {
                $pdo = new PDO($config['dsn'], $config['username'], $config['password']/*, $options*/);
                foreach ($options as $k => $v) {
                    $pdo->setAttribute($k, $v);
                }
                if (!empty($config['charset'])) {
                    $pdo->exec('set names ' . $config['charset']);
                }
                $pdo->exec("set sql_mode='" . ($config['sql_mode'] ?? '') . "'");
                $class = $config['class'] ?? Connection :: class;
                $this->connections[$alias] = new $class($pdo, $config);
            }
            catch (PDOException $e) {
                $this->container['events']->trigger(new Events\ConnectionError(['error' => $e->getMessage()]));
                throw $e;
            }
        }
        return $this->connections[$alias];
    }

    function reconnect($name, $alias = null)
    {
        //unset($this->)
    }

    public function __call($method, $parameters)
    {
        return $this->connection()->$method(...$parameters);
    }

    function disconnect($name = 'default')
    {
        $this->connections[$name]->disconnect();
        unset($this->connections[$name]);
    }

    function get($name)
    {
        return $this->connection($name);
    }


    private function validateConfig($config)
    {
        if (empty($config['dsn'])) {
            return false;
        }
        return true;
    }
}