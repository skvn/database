<?php

namespace Skvn\Database\Relations;

use Skvn\Database\EntityQuery;
use Skvn\Database\Entity;


abstract class Relation
{
    /**
     * @var \Skvn\Database\EntityQuery
     */
    protected $query;
    protected $owner;
    protected $related;
    protected $ownerKey;
    protected $foreignKey;
    protected $multiple = false;


    public function __construct(Entity $owner, $relatedClass, $link = null, $args = [])
    {
        $this->owner = $owner;
        $this->related = new $relatedClass;
        $this->query = $this->related->newQuery();
        if (is_array($link)) {
            list($this->foreignKey, $this->ownerKey) = each($link);
        } else {
            $this->defaultLink();
        }
        foreach ($args as $k => $v) {
            if (property_exists($this, $k)) {
                $this->$k = $v;
            }
        }

        $this->addCriteria();
    }

    abstract protected function defaultLink();
    abstract protected function addCriteria();

    function getModels()
    {
        $method = $this->multiple ? 'all' : 'one';
        return $this->query->$method();
    }

    public function __call($method, $parameters)
    {
        $result = call_user_func_array([$this->query, $method], $parameters);
        if ($result === $this->query) {
            return $this;
        }
        return $result;
    }

    public function __clone()
    {
        $this->query = clone $this->query;
    }




}