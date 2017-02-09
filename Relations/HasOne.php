<?php

namespace Skvn\Database\Relations;

use Skvn\Base\Helpers\Str;

class HasOne extends Relation
{

    protected function defaultLink()
    {
        $this->foreignKey = Str :: classBasename($this->owner) . '_id';
        $this->ownerKey = $this->owner->getPrimaryKeyName();
    }

    protected function addCriteria()
    {
        $this->query->where($this->foreignKey, $this->owner->{$this->ownerKey});
    }

}