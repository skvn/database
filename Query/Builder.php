<?php

namespace Skvn\Database\Query;

use Closure;
use RuntimeException;
use BadMethodCallException;

use Skvn\Base\Helpers\StringHelper;
use Skvn\Base\Exceptions\InvalidArgumentException;


class Builder
{
    public $connection;
    public $grammar;
    public $bindings = [
        'select' => [],
        'join'   => [],
        'where'  => [],
        'having' => [],
        'order'  => [],
        'union'  => [],
    ];

    public $aggregate;
    public $columns;
    public $distinct = false;
    public $from;
    public $joins;
    public $wheres;
    public $groups;
    public $havings;
    public $orders;
    public $limit;
    public $offset;
    public $unions;
    public $unionLimit;
    public $unionOffset;
    public $unionOrders;
    public $lock;

    public $operators = [
        '=', '<', '>', '<=', '>=', '<>', '!=',
        'like', 'like binary', 'not like', 'between', 'ilike',
        '&', '|', '^', '<<', '>>',
        'rlike', 'regexp', 'not regexp',
        '~', '~*', '!~', '!~*', 'similar to',
        'not similar to', 'not ilike', '~~*', '!~~*',
    ];


    public function __construct($connection)
    {
        $this->connection = $connection;
        $this->grammar = new Grammar();
    }

    public function from($table)
    {
        $this->from = $table;
        return $this;
    }


    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        if (is_array($column)) {
            return $this->addArrayOfWheres($column, $boolean);
        }

        list($value, $operator) = $this->prepareValueAndOperator(
            $value, $operator, func_num_args() == 2
        );

        if ($column instanceof Closure) {
            return $this->whereNested($column, $boolean);
        }

        if ($this->invalidOperator($operator)) {
            list($value, $operator) = [$operator, '='];
        }

        if ($value instanceof Closure) {
            return $this->whereSub($column, $operator, $value, $boolean);
        }

        if (is_null($value)) {
            return $this->whereNull($column, $boolean, $operator != '=');
        }

//        if (StringHelper :: contains('->', $column) && is_bool($value)) {
//            $value = new Expression($value ? 'true' : 'false');
//        }

        $type = 'Basic';

        $this->wheres[] = compact(
            'type', 'column', 'operator', 'value', 'boolean'
        );

        if (! $value instanceof Expression) {
            $this->addBinding($value, 'where');
        }

        return $this;
    }

    public function whereNested(Closure $callback, $boolean = 'and')
    {
        call_user_func($callback, $query = $this->forNestedWhere());
        return $this->addNestedWhereQuery($query, $boolean);
    }

    protected function whereSub($column, $operator, Closure $callback, $boolean)
    {
        $type = 'Sub';
        call_user_func($callback, $query = $this->newQuery());

        $this->wheres[] = compact(
            'type', 'column', 'operator', 'query', 'boolean'
        );

        $this->addBinding($query->getBindings(), 'where');

        return $this;
    }

    public function whereNull($column, $boolean = 'and', $not = false)
    {
        $type = $not ? 'NotNull' : 'Null';

        $this->wheres[] = compact('type', 'column', 'boolean');

        return $this;
    }


    public function forNestedWhere()
    {
        return $this->newQuery()->from($this->from);
    }

    public function addNestedWhereQuery($query, $boolean = 'and')
    {
        if (count($query->wheres)) {
            $type = 'Nested';

            $this->wheres[] = compact('type', 'query', 'boolean');

            $this->addBinding($query->getBindings(), 'where');
        }

        return $this;
    }


    protected function addArrayOfWheres($column, $boolean, $method = 'where')
    {
        return $this->whereNested(function ($query) use ($column, $method) {
            foreach ($column as $key => $value) {
                if (is_numeric($key) && is_array($value)) {
                    $query->{$method}(...array_values($value));
                } else {
                    $query->$method($key, '=', $value);
                }
            }
        }, $boolean);
    }

    protected function prepareValueAndOperator($value, $operator, $useDefault = false)
    {
        if ($useDefault) {
            return [$operator, '='];
        } elseif ($this->invalidOperatorAndValue($operator, $value)) {
            throw new InvalidArgumentException('Illegal operator and value combination.');
        }

        return [$value, $operator];
    }

    protected function invalidOperatorAndValue($operator, $value)
    {
        return is_null($value) && in_array($operator, $this->operators) &&
        ! in_array($operator, ['=', '<>', '!=']);
    }

    protected function invalidOperator($operator)
    {
        return ! in_array(strtolower($operator), $this->operators, true);
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
        $this->where(StringHelper::snake($segment), '=', $parameters[$index], $bool);
    }

    public function buildInsert(array $values)
    {
        if (! is_array(reset($values))) {
            $values = [$values];
        } else {
            foreach ($values as $key => $value) {
                ksort($value);
                $values[$key] = $value;
            }
        }
        return $this->grammar->compileInsert($this->from, $values);
    }

    public function insert(array $values)
    {
        if (empty($values)) {
            return true;
        }
        $query = $this->buildInsert($values);
        $this->connection->statement($query, array_values($values));
        return $this->connection->lastInsertId();
    }


    public function addBinding($value, $type = 'where')
    {
        if (! array_key_exists($type, $this->bindings)) {
            throw new InvalidArgumentException("Invalid binding type: {$type}.");
        }

        if (is_array($value)) {
            $this->bindings[$type] = array_values(array_merge($this->bindings[$type], $value));
        } else {
            $this->bindings[$type][] = $value;
        }

        return $this;
    }

    public function getBindings()
    {
        $flat = [];
        foreach ($this->bindings as $type => $bindings) {
            $flat = array_merge($flat, $bindings);
        }
        return $bindings;
    }

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


    public function __call($method, $parameters)
    {
        if (StringHelper::startsWith('where', $method)) {
            return $this->dynamicWhere($method, $parameters);
        }
        throw new BadMethodCallException("Call to undefined method " .static :: class. "::{$method}()");
    }

}