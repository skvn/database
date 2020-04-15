<?php

namespace Skvn\Database;

use Skvn\Base\Helpers\Str;
use Skvn\Base\Exceptions\InvalidArgumentException;
use Skvn\Base\Exceptions\NotFoundException;
use Skvn\Database\Exceptions\QueryException;


class QueryBuilder
{
    protected $connection;

    protected $parts = [
        'aggregate' => [],
        'distinct' => false,
        'columns' => ['*'],
        'from' => null,
        'joins' => [],
        'wheres' => [],
        'groups' => [],
        'havings' => [],
        'orders' => [],
        'limit' => null,
        'offset' => null,
        'bindings' => []
    ];

    protected $queryBindings = [];
    protected $rawSql;



    public function __construct($connection)
    {
        $this->connection = $connection;
    }

    protected function partValid($part)
    {
        if (!array_key_exists($part, $this->parts)) {
            throw new InvalidArgumentException('Unknown part of query: ' . $part);
        }
        return true;
    }


    function __get($name)
    {
        if ($this->partValid($name)) {
            return $this->parts[$name];
        }
    }

    function __isset($name)
    {
        if ($this->partValid($name)) {
            return true;
        }
    }

    function __set($name, $value)
    {
        if ($this->partValid($name)) {
            $this->parts[$name] = $value;
        }
    }

    function push($part, $value)
    {
        if ($this->partValid($part)) {
            if (is_array($value) && isset($value[0])) {
                foreach ($value as $v)
                {
                    $this->parts[$part][] = $v;
                }
            } else {
                $this->parts[$part][] = $value;
            }
        }
    }

    public function select(array $columns)
    {
        if (empty($columns)) {
            return;
        }
        if (in_array('*', $this->columns)) {
            $this->columns = $columns;
        } else {
            $this->columns = array_merge($this->columns, $columns);
        }
        $this->columns = $columns ?: ['*'];
        return $this;
    }

    public function addSelect(array $columns)
    {
        return $this->select($columns);
    }

    public function selectRaw($expression, $bindings = [])
    {
        $this->push('bindings', $bindings);
        return $this->select([new Expression($expression)]);
    }

    public function distinct()
    {
        $this->distinct = true;
        return $this;
    }

    protected function compileColumns()
    {
        if (!empty($this->aggregate)) {
            return;
        }
        $select = $this->distinct ? 'select distinct ' : 'select ';
        return $select . $this->compileColumnList($this->columns);
    }


    public function from($table)
    {
        $this->from = $table;
        return $this;
    }

    protected function compileFrom()
    {
        return 'from '.$this->quoteTable($this->from);
    }


    public function join($table, $first, $second = null, $type = 'inner')
    {
        $this->push('joins', compact('table', 'first', 'second', 'type'));
        return $this;
    }

    public function leftJoin($table, $first, $second)
    {
        return $this->join($table, $first, $second, 'left');
    }

    public function joinRaw($expression)
    {
        $this->push('joins', new Expression($expression));
        return $this;
    }

    protected function compileJoins()
    {
        $joins = array_map(function($join){
            $table = $this->quoteTable($join['table']);
            return $join['type'] . ' join ' . $table . ' on ' . $join['first'] . '=' . $join['second'];
        }, $this->joins);
        return implode(' ', $joins);

    }





    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        if (is_array($column)) {
            foreach ($column as $k => $v) {
                $this->where($k, '=', $v, $boolean);
            }
            return $this;
        }

        if (func_num_args() == 2) {
            $value = $operator;
            $operator = '=';
        }

//        list($value, $operator) = $this->prepareValueAndOperator(
//            $value, $operator,
//        );

//        if ($column instanceof Closure) {
//            return $this->whereNested($column, $boolean);
//        }

//        if ($this->invalidOperator($operator)) {
//            list($value, $operator) = [$operator, '='];
//        }

//        if ($value instanceof Closure) {
//            return $this->whereSub($column, $operator, $value, $boolean);
//        }

        if (is_null($value)) {
            return $this->whereNull($column, $boolean, $operator != '=');
        }

//        if (Str :: contains('->', $column) && is_bool($value)) {
//            $value = new Expression($value ? 'true' : 'false');
//        }

        $type = 'Basic';

        $this->push('wheres', compact('type', 'column', 'operator', 'value', 'boolean'));
        //array_push($this->wheres, compact('type', 'column', 'operator', 'value', 'boolean'));
        //$this->wheres[] = compact('type', 'column', 'operator', 'value', 'boolean');

//        if (! $value instanceof Expression) {
//            $this->addBinding($value);
//        }

        return $this;
    }

    protected function compileWheres()
    {
        if (empty($this->wheres)) {
            return '';
        }
        $parts = array_map(function($item){
            return $item['boolean'].' '.$this->{"compileWhere{$item['type']}"}($item);
        }, $this->wheres);

        if (count($parts) > 0) {
            $where = implode(' ', $parts);
            $where = preg_replace('/and |or /i', '', $where, 1);
            return 'where ' . $where;
        }

        return '';
    }


    public function orWhere($column, $operator = null, $value = null)
    {
        return $this->where($column, $operator, $value, 'or');
    }

    protected function compileWhereBasic($where)
    {
        $value = $this->parameter($where['value']);
        return $this->quote($where['column']).' '.$where['operator'].' '.$value;
    }


    public function whereColumn($first, $operator = null, $second = null, $boolean = 'and')
    {
//        if (is_array($first)) {
//            return $this->addArrayOfWheres($first, $boolean, 'whereColumn');
//        }

//        if ($this->invalidOperator($operator)) {
//            list($second, $operator) = [$operator, '='];
//        }

        $type = 'Column';
        $this->push('wheres', compact('type', 'first', 'operator', 'second', 'boolean'));
        return $this;
    }

    public function orWhereColumn($first, $operator = null, $second = null)
    {
        return $this->whereColumn($first, $operator, $second, 'or');
    }
    protected function compileWhereColumn($where)
    {
        return $this->quote($where['first']).' '.$where['operator'].' '.$this->quote($where['second']);
    }

    public function whereRaw($sql, $bindings = [], $boolean = 'and')
    {
        $type = 'raw';
        //$this->push('wheres', ['type' => 'raw', 'sql' => $sql, 'boolean' => $boolean, 'bindings']);
        $this->push('wheres', compact('type', 'sql', 'boolean', 'bindings'));
        //$this->addBinding($bindings);
        return $this;
    }

    public function orWhereRaw($sql, array $bindings = [])
    {
        return $this->whereRaw($sql, $bindings, 'or');
    }

    protected function compileWhereRaw($where)
    {
        $this->queryBindings = array_merge($this->queryBindings, $where['bindings']);
        return $where['sql'];
    }


    public function whereIn($column, $values, $boolean = 'and', $not = false)
    {
        $type = $not ? 'NotIn' : 'In';

//        if ($values instanceof static) {
//            return $this->whereInExistingQuery(
//                $column, $values, $boolean, $not
//            );
//        }

//        if ($values instanceof Closure) {
//            return $this->whereInSub($column, $values, $boolean, $not);
//        }

//        if ($values instanceof Arrayable) {
//            $values = $values->toArray();
//        }
        $this->push('wheres', compact('type', 'column', 'values', 'boolean'));

//        foreach ($values as $value) {
//            if (! $value instanceof Expression) {
//                $this->addBinding($value);
//            }
//        }

        return $this;
    }

    public function orWhereIn($column, $values)
    {
        return $this->whereIn($column, $values, 'or');
    }

    public function whereNotIn($column, $values, $boolean = 'and')
    {
        return $this->whereIn($column, $values, $boolean, true);
    }

    public function orWhereNotIn($column, $values)
    {
        return $this->whereNotIn($column, $values, 'or');
    }

    protected function compileWhereIn($where)
    {
        if (! empty($where['values'])) {
            return $this->quote($where['column']).' in ('.$this->compileValueList($where['values']).')';
        }
        return '0 = 1';
    }

    protected function compileWhereNotIn($where)
    {
        if (! empty($where['values'])) {
            return $this->quote($where['column']).' not in ('.$this->compileValueList($where['values']).')';
        }
        return '1 = 1';
    }

    public function whereNull($column, $boolean = 'and', $not = false)
    {
        $type = $not ? 'NotNull' : 'Null';
        $this->push('wheres', compact('type', 'column', 'boolean'));
        return $this;
    }

    public function orWhereNull($column)
    {
        return $this->whereNull($column, 'or');
    }

    public function whereNotNull($column, $boolean = 'and')
    {
        return $this->whereNull($column, $boolean, true);
    }

    public function orWhereNotNull($column)
    {
        return $this->whereNotNull($column, 'or');
    }

    protected function compileWhereNull($where)
    {
        return $this->quote($where['column']).' is null';
    }

    protected function compileWhereNotNull($where)
    {
        return $this->quote($where['column']).' is not null';
    }

    public function whereBetween($column, array $values, $boolean = 'and', $not = false)
    {
        $type = 'between';
        $this->push('wheres', compact('column', 'type', 'boolean', 'not', 'values'));
        //$this->addBinding($values, 'where');
        return $this;
    }

    public function orWhereBetween($column, array $values)
    {
        return $this->whereBetween($column, $values, 'or');
    }

    public function whereNotBetween($column, array $values, $boolean = 'and')
    {
        return $this->whereBetween($column, $values, $boolean, true);
    }

    public function orWhereNotBetween($column, array $values)
    {
        return $this->whereNotBetween($column, $values, 'or');
    }

    protected function compileWhereBetween($where)
    {
        $between = $where['not'] ? 'not between' : 'between';
        $this->queryBindings[] = array_shift($where['values']);
        $this->queryBindings[] = array_shift($where['values']);
        return $this->quote($where['column']).' '.$between.' ? and ?';
    }





//    public function whereNested(Closure $callback, $boolean = 'and')
//    {
//        call_user_func($callback, $query = $this->forNestedWhere());
//        return $this->addNestedWhereQuery($query, $boolean);
//    }

//    protected function whereSub($column, $operator, Closure $callback, $boolean)
//    {
//        $type = 'Sub';
//        call_user_func($callback, $query = $this->newQuery());
//
//        $this->wheres[] = compact(
//            'type', 'column', 'operator', 'query', 'boolean'
//        );
//
//        $this->addBinding($query->getBindings(), 'where');
//
//        return $this;
//    }



//    public function forNestedWhere()
//    {
//        return $this->newQuery()->from($this->from);
//    }

//    public function addNestedWhereQuery($query, $boolean = 'and')
//    {
//        if (count($query->wheres)) {
//            $type = 'Nested';
//
//            $this->wheres[] = compact('type', 'query', 'boolean');
//
//            $this->addBinding($query->getBindings(), 'where');
//        }
//
//        return $this;
//    }


//    protected function addArrayOfWheres($column, $boolean, $method = 'where')
//    {
//        return $this->whereNested(function ($query) use ($column, $method) {
//            foreach ($column as $key => $value) {
//                if (is_numeric($key) && is_array($value)) {
//                    $query->{$method}(...array_values($value));
//                } else {
//                    $query->$method($key, '=', $value);
//                }
//            }
//        }, $boolean);
//    }

//    protected function prepareValueAndOperator($value, $operator, $useDefault = false)
//    {
//        if ($useDefault) {
//            return [$operator, '='];
//        } /*elseif ($this->invalidOperatorAndValue($operator, $value)) {
//            throw new InvalidArgumentException('Illegal operator and value combination.');
//        }*/
//
//        return [$value, $operator];
//    }

//    protected function invalidOperatorAndValue($operator, $value)
//    {
//        return is_null($value) && in_array($operator, $this->operators) &&
//        ! in_array($operator, ['=', '<>', '!=']);
//    }

//    protected function invalidOperator($operator)
//    {
//        return ! in_array(strtolower($operator), $this->operators, true);
//    }

    public function groupBy(array $groups = [])
    {
        $this->groups = array_merge($this->groups, $groups);
//        foreach ($groups as $group) {
//            $this->groups = array_merge(
//                (array) $this->groups,
//                array_wrap($group)
//            );
//        }

        return $this;
    }

    protected function compileGroups()
    {
        return 'group by '.$this->compileColumnList($this->groups);
    }


    public function having($column, $operator = null, $value = null, $boolean = 'and')
    {
        $type = 'Basic';
        if (func_num_args() == 2) {
            $value = $operator;
            $operator = '=';
        }

//        list($value, $operator) = $this->prepareValueAndOperator(
//            $value, $operator, func_num_args() == 2
//        );

//        if ($this->invalidOperator($operator)) {
//            list($value, $operator) = [$operator, '='];
//        }
        $this->push('havings', compact('type', 'column', 'operator', 'value', 'boolean'));
//        if (! $value instanceof Expression) {
//            $this->addBinding($value);
//        }

        return $this;
    }

    public function orHaving($column, $operator = null, $value = null)
    {
        return $this->having($column, $operator, $value, 'or');
    }

    public function havingRaw($sql, array $bindings = [], $boolean = 'and')
    {
        $type = 'Raw';
        $this->push('havings', compact('type', 'sql', 'boolean', 'bindings'));
        //$this->addBinding($bindings);
        return $this;
    }

    public function orHavingRaw($sql, array $bindings = [])
    {
        return $this->havingRaw($sql, $bindings, 'or');
    }

    protected function compileHavings()
    {
        $havings = implode(' ', array_map([$this, 'compileHaving'], $this->havings));
        $havings = preg_replace('/and |or /i', '', $havings, 1);

        return 'having ' . $havings;
    }

    protected function compileHaving(array $having)
    {
        if ($having['type'] === 'Raw') {
            $this->queryBindings = array_merge($this->queryBindings, $having['bindings']);
            return $having['boolean'].' '.$having['sql'];
        }
        $column = $this->quote($having['column']);
        $parameter = $this->parameter($having['value']);
        return $having['boolean'].' '.$column.' '.$having['operator'].' '.$parameter;
    }

    public function count($columns = '*')
    {
        return (int) $this->aggregate('count', [$columns]);
    }

    public function min($column)
    {
        return $this->aggregate('min', [$column]);
    }

    public function max($column)
    {
        return $this->aggregate('max', [$column]);
    }

    public function sum($column)
    {
        $result = $this->aggregate('sum', [$column]);

        return $result ?: 0;
    }

    public function avg($column)
    {
        return $this->aggregate('avg', [$column]);
    }

    public function average($column)
    {
        return $this->avg($column);
    }

    public function aggregate($function, $columns = ['*'])
    {
        $q = clone($this);
        $q->columns = [];
        $q->setAggregate($function, $columns);
        $row = $q->one();
        if ($row) {
            $val = $row['aggregate'];
            if (is_numeric($val)) {
                if (is_int($val) || is_float($val)) {
                    return $val;
                }
                if (strpos($val, '.') !== false) {
                    return floatval($val);
                } else {
                    return intval($val);
                }
            }
            return $row['aggregate'];
        }
        return 0;
    }

    public function numericAggregate($function, $columns = ['*'])
    {
        $result = $this->aggregate($function, $columns);


        if (is_int($result) || is_float($result)) {
            return $result;
        }

        return strpos((string) $result, '.') === false
            ? (int) $result : (float) $result;
    }

    protected function setAggregate($function, $columns)
    {
        $this->aggregate = compact('function', 'columns');
        return $this;
    }




    protected function compileAggregate()
    {
        $column = $this->compileColumnList($this->aggregate['columns']);

        if ($this->distinct && $column !== '*') {
            $column = 'distinct '.$column;
        }

        return 'select '.$this->aggregate['function'].'('.$column.') as aggregate';
    }





    public function inRandomOrder($seed = '')
    {
        return $this->orderByRaw($this->compileRandom($seed));
    }

    protected function compileRandom($seed)
    {
        return 'RAND('.$seed.')';
    }

    public function orderBy($column, $direction = 'asc')
    {
        $this->push('orders', ['column' => $column, 'direction' => strtolower($direction) == 'asc' ? 'asc' : 'desc']);
        return $this;
    }

    public function orderByRaw($sql)
    {
        $type = 'Raw';
        $this->push('orders', compact('type', 'sql'));
        return $this;
    }

    public function unorder($column = null)
    {
        if (!empty($column)) {
            $this->parts['orders'] = array_filter($this->parts['orders'], function ($o) { return $o['column'] !== $column; });
        } else {
            $this->parts['orders'] = [];
        }
    }

    protected function compileOrders()
    {
        if (empty($this->orders)) {
            return '';
        }
        $orders = array_map(function ($order) {
            return ! isset($order['sql'])
                ? $this->quote($order['column']).' '.$order['direction']
                : $order['sql'];
        }, $this->orders);

        return 'order by ' . implode(', ', $orders);
    }


    public function skip($value)
    {
        return $this->offset($value);
    }

    public function offset($value)
    {
        $this->offset = max(0, $value);
        return $this;
    }
    
    protected function compileOffset()
    {
        
    }

    public function take($value)
    {
        return $this->limit($value);
    }

    public function limit($value)
    {
        if ($value >= 0) {
            $this->limit = $value;
        }
        return $this;
    }

    protected function compileLimit()
    {
        $str = 'limit ';
        if (!empty($this->offset)) {
            $str .= intval($this->offset) . ', ';
        }
        $str .= intval($this->limit);
        
        return $str;;
    }


    public function forPage($page, $perPage = 15)
    {
        return $this->skip(($page - 1) * $perPage)->take($perPage);
    }





    public function replace(array $values)
    {
        return $this->insert($values, 'replace');
    }

    public function insert(array $values, $stmt = 'insert', $transform = null)
    {
        if (empty($values)) {
            return true;
        }
        if (! is_array(reset($values))) {
            $values = [$values];
        } else {
            foreach ($values as $key => $value) {
                ksort($value);
                $values[$key] = $value;
            }
        }
        $query = $this->compileInsert($values, $stmt, $transform);
        $this->connection->statement($query, $this->queryBindings);
        return $this->connection->lastInsertId();
    }

    protected function compileInsert(array $values, $stmt = 'insert', $transform = null)
    {
        $this->queryBindings = [];
        $table = $this->quoteTable($this->from);
        $columns = $this->compileColumnList(array_keys(reset($values)));
        $paramArr = array_map(function($v){
            return '(' . $this->compileValueList($v) . ')';
        }, $values);
        $parameters = implode(', ', $paramArr);
        $query = $stmt . " into $table ($columns) values $parameters";
        if (is_callable($transform)) {
            $query = $transform($query, $values);
        }
        return $query;
    }

    protected function compileColumnList(array $columns)
    {
        return implode(', ', array_map([$this, 'quote'], $columns));
    }

    protected function compileValueList(array $values)
    {
        return implode(', ', array_map([$this, 'parameter'], $values));
    }



    public function update(array $values)
    {
        $query = $this->compileUpdate($values);
        $this->connection->statement($query, $this->queryBindings);
        return $this->connection->getAffectedRows();
    }

    protected function compileUpdate($values)
    {
        $this->queryBindings = [];
        $table = $this->quoteTable($this->from);
        $columns = $this->compileUpdateColumns($values);
        $where = $this->compileWheres();
        return rtrim("update {$table} set $columns $where");
    }

    protected function compileUpdateColumns($values)
    {
        $columns = [];
        foreach ($values as $k => $v) {
            $columns[] = $this->quote($k) . ' = ' . $this->parameter($v);
        }
        return implode(', ', $columns);
    }

    public function delete()
    {
        $this->connection->statement($this->compileDelete(), $this->queryBindings);
        return $this->connection->getAffectedRows();
    }

    protected function compileDelete()
    {
        $table = $this->quoteTable($this->from);
        $where =  $this->compileWheres();
        $order = $this->compileOrders();
        $limit = !empty($this->limit) ? $this->compileLimit() : "";
        if (empty($where)) {
            throw new InvaidArgumentException('Delete without where cannot be done within query builder');
        }
        return trim("delete from {$table} {$where} {$order} {$limit}");
    }






    public function quoteTable($table)
    {
        if (! $this->isExpression($table)) {
            return $this->quote($table, true);
        }

        return $table->getValue();
    }

    public function isExpression($value)
    {
        return $value instanceof Expression;
    }

    public function quote($value, $prefixAlias = false)
    {
        if ($this->isExpression($value)) {
            return $value->getValue();
        }

        if (strpos(strtolower($value), ' as ') !== false) {
            return $this->quoteAliasedValue($value, $prefixAlias);
        }

        return $this->quoteParts(explode('.', $value));
    }


    protected function quoteAliasedValue($value, $prefixAlias = false)
    {
        $parts = preg_split('/\s+as\s+/i', $value);

        if ($prefixAlias) {
            $parts[1] = $parts[1];
        }

        return $this->quote(
            $parts[0]).' as '.$this->quoteValue($parts[1]
        );
    }

    protected function quoteValue($value)
    {
        if ($value === '*') {
            return $value;
        }

        return '`'.str_replace('`', '``', $value).'`';
    }

    protected function quoteParts($parts)
    {
        $parts = array_map(function($v, $k) use ($parts) {
            return $k == 0 && count($parts) > 1 ? $this->quoteTable($v) : $this->quoteValue($v);
        }, $parts, array_keys($parts));
        return implode('.', $parts);
    }



    public function parameter($value)
    {
        if ($this->isExpression($value)) {
            return $value->getValue();
        } else {
            $this->queryBindings[] = $value;
            return '?';
        }
    }


//    public function addBinding($value)
//    {
//
//        if (is_array($value)) {
//            $this->bindings = array_values(array_merge($this->bindings, $value));
//        } else {
//            $this->bindings[] = $value;
//        }
//
//        return $this;
//    }

//    public function getBindings()
//    {
//        $flat = [];
//        foreach ($this->bindings as $type => $bindings) {
//            $flat = array_merge($flat, $bindings);
//        }
//        return $bindings;
//    }

//    protected function cleanBindings(array $bindings)
//    {
//        return array_values(array_filter($bindings, function ($binding) {
//            return ! $binding instanceof Expression;
//        }));
//    }


    public function newQuery()
    {
        return new static($this->connection);
    }

    protected function compileSelect()
    {
        $this->queryBindings = [];
        $parts = [];
        foreach (array_keys($this->parts) as $part) {
            if (! empty($this->$part)) {
                $method = 'compile'.ucfirst($part);
                $parts[$part] = $this->$method();
            }
        }
        $parts = array_filter($parts, function($part){ return !empty($part); });
        return implode(' ', $parts);
    }

    protected function compileBindings()
    {

    }

    function rawSql($sql, $bindings = [])
    {
        $this->rawSql = $sql;
        $this->queryBindings = $bindings;
        return $this;
    }

    public function toSql()
    {
        return !empty($this->rawSql) ? ($this->rawSql . ' ' . $this->compileLimit() . ' ' . $this->compileOffset()) : $this->compileSelect();
    }

    public function find($id, $columns = ['*'], $pk = 'id')
    {
        return $this->where($pk, '=', $id)->first($columns);
    }

    public function value($column)
    {
        $result = (array) $this->first([$column]);

        return count($result) > 0 ? reset($result) : null;
    }

    public function first()
    {
        $this->limit(1);
        return $this->connection->selectOne($this->toSql(), $this->queryBindings);
    }

    public function one()
    {
        return $this->first();
    }

    public function get()
    {
        return $this->connection->select($this->toSql(), $this->queryBindings);
    }

    public function all()
    {
        return $this->get();
    }

    public function iterator()
    {
        return $this->connection->iterator($this->toSql(), $this->queryBindings);
    }

    public function chunk($count, callable $callback)
    {
        if (empty($this->orders) && empty($this->rawSql)) {
            throw new QueryException('Order by is required for chunked resultset processing');
        }
        $page = 1;
        do {
            $results = $this->forPage($page, $count)->get();
            $countResults = count($results);
            if ($countResults == 0) {
                break;
            }
            if ($callback($results) === false) {
                return false;
            }
            $page++;
        } while ($countResults == $count);

        return true;
    }

    public function forPageAfterId($perPage = 15, $lastId = 0, $column = 'id')
    {
        $this->unorder($column);

        return $this->where($column, '>', $lastId)
                    ->orderBy($column, 'asc')
                    ->take($perPage);
    }


    public function chunkById($count, callable $callback, $column = 'id', $alias = null)
    {
        $alias = $alias ?: $column;
        $lastId = 0;
        do {
            $clone = clone $this;
            $results = $clone->forPageAfterId($count, $lastId, $column)->get();
            $countResults = count($results);
            if ($countResults == 0) {
                break;
            }
            if ($callback($results) === false) {
                return false;
            }
            $lastId = end($results)[$alias];
        } while ($countResults == $count);

        return true;
    }


    public function each(callable $callback, $count = 1000)
    {
        return $this->chunk($count, function ($results) use ($callback) {
            foreach ($results as $key => $value) {
                if ($callback($value, $key) === false) {
                    return false;
                }
            }
        });
    }







    public function __call($method, $parameters)
    {
        if (Str :: startsWith('where', $method)) {
            return $this->dynamicWhere($method, $parameters);
        }
        throw new \BadMethodCallException("Call to undefined method " .static :: class. "::{$method}()");
    }

    public function dynamicWhere($method, $parameters)
    {
        $finder = substr($method, 5);

        $segments = preg_split(
            '/(And|Or)(?=[A-Z])/', $finder, -1, PREG_SPLIT_DELIM_CAPTURE
        );

        $connector = 'and';

        $index = 0;

        foreach ($segments as $segment) {
            if ($segment != 'And' && $segment != 'Or') {
                $this->addDynamic($segment, $connector, $parameters, $index);

                $index++;
            }
            else {
                $connector = $segment;
            }
        }

        return $this;
    }

    protected function addDynamic($segment, $connector, $parameters, $index)
    {
        $bool = strtolower($connector);
        $this->where(Str :: underscore($segment), '=', $parameters[$index], $bool);
    }

    function getConnection()
    {
        return $this->connection;
    }


}