<?php

namespace Skvn\Database\Relations;

use Skvn\Base\Helpers\Str;

class HasMany extends Relation
{
    protected $multiple = true;

    protected function defaultLink()
    {
        $this->ownerKey = $this->owner->getPrimaryKey();
        $this->foreignKey = Str :: classBasename($this->owner) . '_id';
    }

    protected function addCriteria()
    {
        $this->query->where($this->foreignKey, $this->owner->{$this->ownerKey});
    }


}