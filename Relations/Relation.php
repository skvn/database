<?php

namespace Skvn\Database\Relations;

use Skvn\Database\EntityQuery;
use Skvn\Database\Entity;

abstract class Relation
{
    protected $query;
    protected $owner;
    protected $related;
    protected $ownerKey;
    protected $foreignKey;
    protected $multiple = false;


    public function __construct(Entity $owner, $relatedClass, $link = null)
    {
        $this->owner = $owner;
        $this->related = new $relatedClass;
        $this->query = $this->related->newQuery();
        if (is_array($link)) {
            list($this->foreignKey, $this->ownerKey) = each($link);
        } else {
            $this->defaultLink();
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


}