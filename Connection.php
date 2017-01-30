<?php

namespace Skvn\Database;

use PDO;
use Closure;
use Exception;
use PDOException;
use DateTimeInterface;
use Skvn\Base\Container;
use Skvn\Base\StringHelper;

class Connection
{
    protected $pdo;
    protected $config = [];
    protected $affectedRows = 0;
    protected $events;

    public function __construct(PDO $pdo, array $config = [])
    {
        $this->pdo = $pdo;
        $this->config = $config;
        $this->events = Container :: getInstance()['events'];
    }

    public function table($table)
    {
        return $this->query()->from($table);
    }

    public function query()
    {
        return new QueryBuilder($this);
    }

    public function selectOne($query, $bindings = [])
    {
        $statement = $this->execute($query, $bindings);
        return $statement->fetch();
    }

    public function select($query, $bindings = [])
    {
        $statement = $this->execute($query, $bindings);
        return $statement->fetchAll();
    }

    public function iterator($query, $bindings = [])
    {
        $statement = $this->execute($query, $bindings);

        while ($record = $statement->fetch()) {
            yield $record;
        }
    }

    public function insert($table, $values)
    {
        $id = $this->table($table)->insert($values);
        $this->events->trigger(new Events\Insert([
            'table' => $table,
            'values' => $values,
            'new_id' => $id
        ]));
        return $id;
    }

    public function update($table, $values, $pk)
    {
        $id = $values[$pk];
        $query = $this->table($table)->where($pk, '=', $id);
        unset($values[$pk]);
        $rows = $query->update($values);
        $this->events->trigger(new Events\Update([
            'table' => $table,
            'id' => $id,
            'values' => $values
        ]));
        return $rows;
    }

    public function delete($query, $bindings = [])
    {
        return $this->affectingStatement($query, $bindings);
    }

    public function statement($query, $bindings = [])
    {
        $this->execute($query, $bindings);
    }

    public function affectingStatement($query, $bindings = [])
    {
        $this->execute($query, $bindings);
        return $this->affectedRows;
    }

    public function unprepared($query)
    {
        $this->execute($query, false);
        return $this->affectedRows;
    }

    function execute($query, $bindings = [])
    {
        $evt = [
            'query' => $query,
            'bindings' => $bindings,
            'connection' => $this
        ];

        if ($this->events->trigger(new Events\QueryReceived($evt)) === false) {
            $this->events->trigger(new Events\QuerySkipped($evt));
            return true;
        }

        $t = microtime(true);
        $statement = false;
        try {
            if ($bindings === false) {
                $this->affectedRows = $this->pdo->exec($query);
            } else {
                $statement = $this->pdo->prepare($query);
                $statement->setFetchMode(PDO :: FETCH_ASSOC);
                $this->bindValues($statement, $this->prepareBindings($bindings));
                $statement->execute();
                $this->affectedRows = $statement->rowCount();
            }
        }
        catch (PDOException $e) {
            $evt['error'] = $e->getMessage();
            $this->events->trigger(new Events\QueryError($evt));
            throw new Exceptions\QueryException($e->getMessage());
        }
        $evt['time'] = round(microtime(true) - $t, 4);
        $this->events->trigger(new Events\QueryExecuted($evt));
        return $statement;
    }



    public function bindValues($statement, $bindings)
    {
        foreach ($bindings as $key => $value) {
            $statement->bindValue(
                is_string($key) ? $key : $key + 1, $value,
                is_int($value) || is_float($value) ? PDO::PARAM_INT : PDO::PARAM_STR
            );
        }
    }

    public function lastInsertId()
    {
        return $this->pdo->lastInsertId();
    }

    public function getAffectedRows()
    {
        return $this->affectedRows;
    }

    public function quote($value)
    {
        return $this->pdo->quote($value);
    }


    public function prepareBindings(array $bindings)
    {
        return $bindings;
        $grammar = $this->getQueryGrammar();

        foreach ($bindings as $key => $value) {
            if ($value instanceof DateTimeInterface) {
                $bindings[$key] = $value->format($grammar->getDateFormat());
            } elseif ($value === false) {
                $bindings[$key] = 0;
            }
        }

        return $bindings;
    }

    public function disconnect()
    {
        $this->pdo = null;
    }

    public function raw($value)
    {
        return new Expression($value);
    }

    public function startTransaction()
    {
        return $this->pdo->beginTransaction();
    }

    public function commit()
    {
        return $this->pdo->commit();
    }

    public function rollBack()
    {
        return $this->pdo->rollBack();
    }

    public function transaction(\Closure $callback)
    {
        try {
            $this->startTransaction();
            $result = $callback();
            $this->commit();
            return $result;
        } catch (Exception $e) {
            $this->rollBack();
            return false;
        }
    }



//    protected function causedByDeadlock($message)
//    {
//        return StringHelper :: contains([
//            'Deadlock found when trying to get lock',
//            'deadlock detected',
//            'The database file is locked',
//            'A table in the database is locked',
//            'has been chosen as the deadlock victim',
//        ], $message);
//    }
//
//
//    protected function causedByLostConnection($message)
//    {
//        return StringHelper :: contains([
//            'server has gone away',
//            'no connection to the server',
//            'Lost connection',
//            'is dead or not enabled',
//            'Error while sending',
//            'decryption failed or bad record mac',
//            'server closed the connection unexpectedly',
//            'SSL connection has been closed unexpectedly',
//            'Error writing data to the connection',
//            'Resource deadlock avoided',
//        ], $message);
//    }

}
