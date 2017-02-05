<?php

namespace Skvn\Database;

use PDO;
use PDOException;
use Skvn\Base\Container;

class DatabaseDispatcher
{
    private $connections = [];
    private $container = null;

    function __construct()
    {
        $this->container = Container :: getInstance();
    }

    function connection($name = "default", $alias = null)
    {
        if (is_null($alias)) {
            $alias = $name;
        }
        if (!isset($this->connections[$alias])) {
            $config = $this->container['config']['database.' . $name];
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
                $this->connections[$alias] = new Connection($pdo, $config);
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


    private function validateConfig($config)
    {
        return true;
    }
}