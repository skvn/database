<?php

namespace Skvn\Database;

use Skvn\Base\Traits\ArrayOrObjectAccessImpl;
use Skvn\Base\Helpers\Str;
use Skvn\Base\Exceptions\ImplementationException;
use Skvn\Base\Exceptions\InvalidArgumentException;
use Skvn\Base\Container;

abstract class Entity implements \ArrayAccess
{
    use ArrayOrObjectAccessImpl;

    protected $table;
    protected $primaryKey = 'id';
    protected $connectionName = 'default';
    protected $timestamps = false;
    protected $queryClass = EntityQuery :: class;


    protected $connection;

    public $exists = false;

    protected $attributes = [];
    protected $original = [];
    protected $casts = [];
    protected $dates = [];
    protected $relations = [];

    const COLUMN_CREATED_AT = 'created_at';
    const COLUMN_UPDATED_AT = 'updated_at';



    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    protected function onBeforeSave() {}
    protected function onBeforeUpdate() {}
    protected function onAfterUpdate() {}
    protected function onBeforeInsert() {}
    protected function onAfterInsert() {}
    protected function onAfterSave() {}
    protected function onBeforeDelete() {}
    protected function onAfterDelete() {}

    public function save()
    {
        $query = $this->newQuery();
        if ($this->onBeforeSave() === false) {
            return false;
        }
        if ($this->timestamps) {
            $this->updateTimestamps();
        }
        if ($this->exists) {
            if ($this->onBeforeUpdate() === false) {
                return false;
            }
            $dirty = $this->getDirty();
            if (!empty($dirty)) {
                $query->where($this->primaryKey, $this->original[$this->primaryKey] ?? $this->getAttribute($this->primaryKey));
                $query->update($dirty);
                $this->onAfterUpdate();
            }
        } else {
            if ($this->onBeforeInsert() === false) {
                return false;
            }
            if (empty($this->attributes)) {
                return true;
            }
            $id = $query->insert($this->attributes);
            $this->setAttribute($this->primaryKey, $id);
            $this->exists = true;
            $this->onAfterInsert();
        }
        $this->onAfterSave();
        $this->syncOriginal();


        return true;
    }

    public function delete()
    {
        if (empty($this->primaryKey)) {
            throw new ImplementationException('No primary key defined on model ' . get_class($this));
        }
        if (! $this->exists) {
            return false;
        }
        if ($this->onBeforeDelete() === false) {
            return false;
        }
        $query = $this->newQuery();
        $query->where($this->primaryKey, $this->getId());
        $query->delete();
        $this->exists = false;
        $this->onAfterDelete();
        return true;
    }

    protected function updateTimestamps()
    {
        $time = time();
        if (! $this->isDirty(static :: COLUMN_UPDATED_AT)) {
            $this->{static :: COLUMN_UPDATED_AT} = $time;
        }
        if (! $this->exists && ! $this->isDirty(static :: COLUMN_CREATED_AT)) {
            $this->{static :: COLUMN_CREATED_AT} = $time;
        }
    }

    public function syncOriginal()
    {
        $this->original = $this->attributes;

        return $this;
    }

    public function getAttribute($key)
    {
        if (! $key) {
            return;
        }
        if (array_key_exists($key, $this->attributes) || $this->hasMutator($key)) {
            return $this->getAttributeValue($key);
        }

        if (method_exists(self::class, $key)) {
            return;
        }
        return $this->getRelationValue($key);
    }

    public function getRelationValue($key)
    {
        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key];
        }
        if (method_exists($this, $key)) {
            $relation = $this->$key();
            if (! $relation instanceof Relations\Relation) {
                throw new ImplementationException('Relationship method must return a relation object ');
            }
            $this->relations[$key] = $relation->getModels();
        }
        return $this->relations[$key] ?? null;
    }


    public function setAttribute($key, $value)
    {
        if ($this->hasMutator($key, 'set')) {
            return $this->mutateAttribute($key, $value, 'set');
        }

        if (!empty($value)) {
            if (array_key_exists($key, $this->casts) || array_key_exists($key, $this->dates)) {
                $value = strtotime($value);
            }
            if (in_array($this->casts[$key] ?? null, ['array', 'object', 'json'])) {
                $value = json_encode($value);
            }
        }
        $this->attributes[$key] = $value;
        return $this;
    }


    public function getAttributeValue($key)
    {
        $value = $this->attributes[$key] ?? null;
        if ($this->hasMutator($key)) {
            return $this->mutateAttribute($key, $value);
        }

        $type = $this->casts[$key] ?? null;
        $dateFormat = $this->dates[$key] ?? null;
        if (!empty($dateFormat)) {
            $type = "date";
            if (empty($value)) {
                $value = null;
            }
        }
        if (is_null($value)) {
            return $value;
        }
        if ($type) {
            return $this->castValue($value, $type);
        }

        return $value;
    }

    protected function castValue($value, $type, $format = null)
    {
        switch ($type) {
            case 'int':
            case 'integer':
                return (int) $value;
            case 'real':
            case 'float':
            case 'double':
                return (float) $value;
            case 'string':
                return (string) $value;
            case 'bool':
            case 'boolean':
                return (bool) $value;
            case 'object':
                return json_decode($value);
            case 'array':
            case 'json':
                return json_decode($value, true);
//            case 'collection':
//                return new BaseCollection($this->fromJson($value));
            case 'date':
                return date($format ?? 'Y-m-d', $value);
            case 'datetime':
                return date($format ?? 'Y-m-d H:i:s', $value);
            case 'time':
                return date($format ?? 'H:i:s', $value);
            default:
                return $value;
        }
    }


    protected function mutatorName($key, $type = 'get')
    {
        return $type . Str :: studly($key) . 'Attribute';
    }

    protected function hasMutator($key, $type = 'get')
    {
        return method_exists($this, $this->mutatorName($key, $type));
    }

    protected function mutateAttribute($key, $value, $type = 'get')
    {
        return $this->{$this->mutatorName($key, 'get')}($value);
    }


    function fill(array $attributes, $force = false)
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key) || $force) {
                $this->setAttribute($key, $value);
            }
        }
        return $this;
    }

    function fillRaw(array $attributes, $sync = false)
    {
        $this->attributes = $attributes;
        if ($sync) {
            $this->syncOriginal();
        }
    }

    function isFillable($key)
    {

    }

    public function getKey()
    {
        return $this->getAttribute($this->primaryKey);
    }

    function getId()
    {
        return $this->getKey();
    }

    function get($name)
    {
        return $this->getAttribute($name);
    }

    public function getOriginal($key = null, $default = null)
    {
        return $this->original[$key] ?? $default;
    }

    function getAllMutators()
    {
        $list = [];
        foreach (get_class_methods(static :: class) as $method) {
            if (preg_match('#^get(.+)Attribute$#', $method, $m)) {
                $list[Str :: snake($m[1])] = $method;
            }
        }
        return $list;
    }

    public function isDirty($attributes = null)
    {
        $dirty = $this->getDirty();
        if (is_null($attributes)) {
            return count($dirty) > 0;
        }
        $attributes = is_array($attributes) ? $attributes : func_get_args();
        foreach ($attributes as $attribute) {
            if (array_key_exists($attribute, $dirty)) {
                return true;
            }
        }
        return false;
    }

    public function isClean($attributes = null)
    {
        return ! $this->isDirty(...func_get_args());
    }

    public function getDirty()
    {
        $dirty = [];
        foreach ($this->attributes as $key => $value) {
            if (! array_key_exists($key, $this->original)) {
                $dirty[$key] = $value;
            } elseif ($value !== $this->original[$key]) {
                $dirty[$key] = $value;
            }
        }
        return $dirty;
    }

    public static function query()
    {
        return (new static)->newQuery();
    }

    public function newQuery()
    {
        $class = $this->queryClass;
        $builder = new $class($this->getConnection()->query());
        return $builder->setModel($this)/*->with($this->with)*/;

        return $builder;
    }





    public function newInstance($attributes = [], $exists = false)
    {
        $model = new static((array) $attributes);
        $model->exists = $exists;
        return $model;
    }


    function set($name, $value)
    {
        $this->setAttribute($name, $value);
    }

    public function __get($key)
    {
        return $this->getAttribute($key);
    }

    public function __set($key, $value)
    {
        $this->setAttribute($key, $value);
    }

    public function offsetUnset($offset)
    {
        unset($this->$offset);
    }

    public function __isset($key)
    {
        return ! is_null($this->getAttribute($key));
    }

    public function __unset($key)
    {
        unset($this->attributes[$key], $this->relations[$key]);
    }

    public function __call($method, $parameters)
    {
        return $this->newQuery()->$method(...$parameters);
    }

    public static function __callStatic($method, $parameters)
    {
        return (new static)->$method(...$parameters);
    }

    public function __toString()
    {
        return $this->toJson();
    }

    public function jsonSerialize()
    {
        return $this->toArray();
    }

    function dump()
    {
        return [
            'class' => get_class($this),
            'table' => $this->getTable(),
            'id' => $this->getId(),
            'data' => $this->toArray()
        ];
    }

    public function toJson($options = 0)
    {
        $json = json_encode($this->jsonSerialize(), $options);

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new InvalidArgumentException('Error encoding model ['.get_class($this).'] with ID ['.$this->getKey().'] to JSON: '.json_last_error_msg());
        }

        return $json;
    }

    function attributesToArray()
    {
        $attrs = [];
        foreach ($this->attributes as $k => $v) {
            $attrs[$k] = $this->$k;
        }
        return $attrs;
    }

    function relationsToArray()
    {
        return [];
    }

    public function toArray()
    {
        return array_merge($this->attributesToArray(), $this->relationsToArray());
    }

    public function getTable()
    {
        return $this->table;
    }

    function getPrimaryKeyName($withTable = false)
    {
        $prefix = $withTable ? ($this->getTable() . '.') : '';
        return $prefix . $this->primaryKey;
    }

    function getConnection()
    {
        if (is_null($this->connection)) {
            $this->connection = Container :: getInstance()->get('db')->connection($this->connectionName);
        }
        return $this->connection;
    }

    function setConnection($conn)
    {
        $this->connection = $conn;
        return $this;
    }

    function hasOne($relatedClass, $link = null)
    {
        return new Relations\HasOne($this, $relatedClass, $link);
    }

    function belongsTo($relatedClass, $link = null)
    {
        return new Relations\BelongsTo($this, $relatedClass, $link);
    }

    function hasMany($relatedClass, $link = null)
    {
        return new Relations\HasMany($this, $relatedClass, $link);
    }

    function belongsToMany($relatedClass, $link = null, $table = null)
    {
        return new Relations\BelongsToMany($this, $relatedClass, $link, ['viaTable' => $table]);
    }






}