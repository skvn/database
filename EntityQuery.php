<?php

namespace Skvn\Database;

use Skvn\Base\Exceptions\NotFoundException;
use Skvn\Base\Helpers\StringHelper;

class EntityQuery
{
    protected $query;
    protected $model;
    protected $eagerLoad = [];


    public function __construct(QueryBuilder $query)
    {
        $this->query = $query;
    }


    public function with($relations)
    {
        $eagerLoad = $this->parseWithRelations(is_array($relations) ? $relations : func_get_args());
        $this->eagerLoad = array_merge($this->eagerLoad, $eagerLoad);
        return $this;
    }

    protected function parseWithRelations(array $relations)
    {
        $results = [];
        foreach ($relations as $name => $constraints) {
            if (is_numeric($name)) {
                $name = $constraints;
                $constraints = function() {};
            }
            if (StringHelper :: contains('.', $name)) {
                $path = "";
                foreach (explode('.', $name) as $part) {
                    $path .= ((!empty($path) ? '.' : '') . $part);
                    if (!isset($results[$path])) {
                        $results[$path] = function() {};
                    }
                }
            }
            $results[$name] = $constraints;
        }
        return $results;
    }




    public function setModel(Entity $model)
    {
        $this->model = $model;

        $this->query->from($model->getTable());

        return $this;
    }

//    public function whereKey($id)
//    {
//        if (is_array($id)) {
//            $this->query->whereIn($this->model->getQualifiedKeyName(), $id);
//        } else {
//            $this->query->where($this->model->getQualifiedKeyName(), $id);
//        }
//        return $this;
//    }

//    public function where($column, $operator = null, $value = null, $boolean = 'and')
//    {
//        if ($column instanceof Closure) {
//            $query = $this->model->newQueryWithoutScopes();
//
//            $column($query);
//
//            $this->query->addNestedWhereQuery($query->getQuery(), $boolean);
//        } else {
//            $this->query->where(...func_get_args());
//        }

//        return $this;
//    }

//    public function orWhere($column, $operator = null, $value = null)
//    {
//        return $this->where($column, $operator, $value, 'or');
//    }

    function findOne($id)
    {
        $this->query->where($this->model->getQualifiedKeyName(), $id);
        return $this->one();
    }

    function findByIds($ids)
    {
        $this->query->whereIn($this->model->getQualifiedKeyName(), $ids);
        return $this->all();
    }

    public function find($id)
    {
        if (is_array($id)) {
            return $this->findByIds($id);
        }
        return $this->findOne();
    }

    public function findMany($ids)
    {
        if (empty($ids)) {
            return [];
        }
        return $this->findByIds($ids);
    }

    public function findOrFail($id)
    {
        $result = $this->find($id);
        if (is_array($id)) {
            if (count($result) == count(array_unique($id))) {
                return $result;
            }
        } elseif (! is_null($result)) {
            return $result;
        }
        throw new NotFoundException('Model ' . get_class($this->model) . '#' . (is_array($id) ? implode(',', $id) : $id) . ' not found', [
            'model' => $this->model
        ]);
    }

    public function firstOrFail()
    {
        if (! is_null($model = $this->first())) {
            return $model;
        }
        throw new NotFoundException('Model ' . get_class($this->model) . ' not found', [
            'model' => $this->model
        ]);
    }


    public function findOrNew($id)
    {
        if (! is_null($model = $this->find($id))) {
            return $model;
        }
        return $this->objectify();
    }

    public function firstOrNew(array $attributes, array $values = [])
    {
        if (! is_null($instance = $this->where($attributes)->first())) {
            return $instance;
        }
        return $this->objectify($attributes + $values);
    }

    public function firstOrCreate(array $attributes, array $values = [])
    {
        $instance = $this->firstOrNew($attributes, $values);
        $instance->save();
        return $instance;
    }

    public function fromQuery($sql, $bindings = [])
    {
        $this->query->rawSql($sql, $bindings);
        return $this->all();
    }

    function findBySql($sql, $bindings = [])
    {
        return $this->fromQuery($sql, $bindings);
    }


    function all()
    {
        $models = array_map(function($item){
            return $this->objectify($item);
        }, $this->query->all());
        if (count($models)) {
            $models = $this->eagerLoadRelations($models);
        }
        return $models;
    }

    public function get()
    {
        $this->all();
    }

    function one()
    {
        $this->query->take(1);
        return $this->objectify($this->query->one());
    }

    function first()
    {
        return $this->one();
    }

    function objectify(array $attributes = [])
    {
        $exists = !empty($attributes[$this->model->getPrimaryKeyName()]);
        $model = $this->model->newInstance([], $exists);
        $model->setConnection($this->query->getConnection());
        $model->fillRaw($attributes, true);
        return $model;
    }










    public function eagerLoadRelations(array $models)
    {
        foreach ($this->eagerLoad as $name => $constraints) {
            if (strpos($name, '.') === false) {
                $models = $this->eagerLoadRelation($models, $name, $constraints);
            }
        }
        return $models;
    }

    protected function eagerLoadRelation(array $models, $name, \Closure $constraints)
    {
        $relation = $this->getRelation($name);
        $relation->addEagerConstraints($models);

        $constraints($relation);

        return $relation->match(
            $relation->initRelation($models, $name),
            $relation->getEager(), $name
        );
    }

    public function getRelation($name)
    {
        $relation = Relation::noConstraints(function () use ($name) {
            try {
                return $this->getModel()->{$name}();
            } catch (BadMethodCallException $e) {
                throw RelationNotFoundException::make($this->getModel(), $name);
            }
        });

        $nested = $this->relationsNestedUnder($name);

        if (count($nested) > 0) {
            $relation->getQuery()->with($nested);
        }

        return $relation;
    }

    public function __call($method, $parameters)
    {
        $scalar = [
            'insert', 'insertGetId', 'getBindings', 'toSql',
            'exists', 'count', 'min', 'max', 'avg', 'sum', 'getConnection',
        ];
        $result = $this->query->{$method}(...$parameters);
        return in_array($method, $scalar) ? $result : $this;
    }

    public function __clone()
    {
        $this->query = clone $this->query;
    }














}