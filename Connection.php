<?php

namespace Skvn\Database;

use PDO;
use PDOStatement;
use Closure;
use Exception;
use PDOException;
use DateTimeInterface;
use Skvn\Base\Helpers\Str;
use Skvn\Base\Traits\AppHolder;


class Connection
{
    use AppHolder;


    protected $pdo;
    protected $config = [];
    protected $affectedRows = 0;
    protected $events;
    protected $name;
    protected $dispatcher;

    public function __construct(PDO $pdo, array $config = [], $name = null, $dispatcher = null)
    {
        $this->pdo = $pdo;
        $this->config = $config;
        $this->name = $name;
        $this->dispatcher = $dispatcher;
    }
    
    public function reconnect($attempt)
    {
        if (empty($this->name) || empty($this->dispatcher)) {
            return false;
        }
        $this->pdo = null;
        $conn = $this->dispatcher->connection($this->name, $this->name . '_attempt_' . $attempt);
        $this->pdo = $conn->getPDO();
        return true;
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
        $row = $statement->fetch();
        $statement->closeCursor();
        return $row;
    }

    public function selectScalar($query, $bindings = [])
    {
        $row = $this->selectOne($query, $bindings);
        return !empty($row) ? array_shift($row) : null;
    }


    public function select($query, $bindings = [])
    {
        $statement = $this->execute($query, $bindings);
        $recordset = $statement->fetchAll();
        $statement->closeCursor();
        return $recordset;
    }

    public function selectColumn($column, $query, $bindings = [])
    {
        return array_map(function($row) use ($column) {
            return $row[$column] ?? null;
        }, $this->select($query, $bindings));
    }

    public function selectIndexed($index, $query, $bindings = [])
    {
        $rs = $this->select($query, $bindings);
        $indexed = [];
        foreach ($rs as $r) {
            $indexed[$r[$index] ?? 0] = $r;
        }
        unset($rs);
        return $indexed;
    }

    public function iterator($query, $bindings = [])
    {
        $statement = $this->execute($query, $bindings);

        while ($record = $statement->fetch()) {
            yield $record;
        }
        $statement->closeCursor();
    }

    public function insert($table, $values, $transform = null)
    {
        $id = $this->table($table)->insert($values, 'insert', $transform);
        $this->app->triggerEvent(new Events\Insert([
            'table' => $table,
            'values' => $values,
            'new_id' => $id
        ]));
        return $id;
    }
    
    public function replace($table, $values)
    {
        $id = $this->table($table)->replace($values);
        $this->app->triggerEvent(new Events\Replace([
            'table' => $table,
            'values' => $values,
            'new_id' => $id
        ]));
        return $id;
    }

    public function update($table, $values, $pk = 'id')
    {
        $id = $values[$pk];
        $query = $this->table($table)->where($pk, '=', $id);
        unset($values[$pk]);
        $rows = $query->update($values);
        $this->app->triggerEvent(new Events\Update([
            'table' => $table,
            'id' => $id,
            'values' => $values
        ]));
        return $rows;
    }

    public function delete($table, $id, $pk = 'id')
    {
        return $this->table($table)->where($pk, $id)->delete();
    }

    public function statement($query, $bindings = [])
    {
        $statement = $this->execute($query, $bindings);
        if ($statement instanceof PDOStatement) {
            $statement->closeCursor();
        }
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

        if ($this->app->triggerEvent(new Events\QueryReceived($evt)) === false) {
            $this->app->triggerEvent(new Events\QuerySkipped($evt));
            return true;
        }

        $t = microtime(true);
        $attempt = 1;
        while ($attempt <= 3) {
            $statement = false;
            try {
                if ($bindings === false) {
                    $this->affectedRows = $this->pdo->exec($query);
                } else {
                    list($query, $bindings) = $this->flatArrayBindings($query, $bindings);
                    $evt['query']= $query;
                    $evt['bindings'] = $bindings;
                    $statement = $this->pdo->prepare($query, [PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true]);
                    $statement->setFetchMode(PDO :: FETCH_ASSOC);
                    $this->bindValues($statement, $bindings);
                    $statement->execute();
                    $this->affectedRows = $statement->rowCount();
                    break;
                }
            }
            catch (PDOException $e) {
                $evt['error'] = $e->getMessage();
                if (Str::pos('has gone away', $e->getMessage()) === false || $attempt >= 3) {
                    $this->app->triggerEvent(new Events\QueryError($evt));
                    throw new Exceptions\QueryException($e->getMessage());
                }
                $attempt++;
                $evt['name'] = $this->name;
                $evt['attempt'] = $attempt;
                if ($this->reconnect($attempt) === false) {
                    $this->app->triggerEvent(new Events\ReconnectError($evt));
                    $this->app->triggerEvent(new Events\QueryError($evt));
                    throw new Exceptions\QueryException($e->getMessage());
                }
                $this->app->triggerEvent(new Events\Reconnected($evt));
            }
        }
        $evt['time'] = round(microtime(true) - $t, 4);
        $this->app->triggerEvent(new Events\QueryExecuted($evt));
        return $statement;
    }

    protected function flatArrayBindings($query, $bindings)
    {
        if (strpos($query, '(?)') === false) {
            return [$query, $bindings];
        }
        for ($i=0; $i<count($bindings); $i++) {
            if (is_array($bindings[$i])) {
                $r = $bindings[$i];
                array_splice($bindings, $i, 1, $r);
                if (empty($r)) {
                    $query = preg_replace('#\(\?\)#', '(0)', $query, 1);
                } else {
                    $query = preg_replace('#\(\?\)#', '(' . implode(', ', array_fill(0, count($r), '?')) . ')', $query, 1);
                }
                return $this->flatArrayBindings($query, $bindings);
            }
        }
        return [$query, $bindings];
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
        if ($this->pdo->inTransaction())
        {
            return $this->pdo->rollBack();
        }
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
    
    public function getPDO()
    {
        return $this->pdo;
    }




}
