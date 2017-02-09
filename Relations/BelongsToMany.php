<?php

namespace Skvn\Database\Relations;

use Skvn\Base\Helpers\Str;
use Skvn\Base\Exceptions\ImplementationException;

class BelongsToMany extends Relation
{
    protected $viaTable;
    protected $multiple = true;

    function viaTable($table)
    {
        $this->viaTable = $table;
        return $this;
    }

    protected function defaultLink()
    {
        $this->ownerKey = Str :: classBasename($this->owner) . '_id';
        $this->foreignKey = Str :: classBasename($this->related) . '_id';
    }

    protected function addCriteria()
    {
        if (empty($this->viaTable)) {
            throw new ImplementationException("Link table not defined");
        }
        $this->query
            ->join($this->viaTable, $this->related->getPrimaryKeyName(true), $this->viaTable . '.' . $this->foreignKey)
            ->where($this->viaTable . '.' . $this->ownerKey, $this->owner->getId());
    }


}