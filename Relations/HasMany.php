<?php

namespace Skvn\Database\Relations;

use Skvn\Base\Helpers\StringHelper;

class HasMany extends Relation
{
    protected $multiple = true;

    protected function defaultLink()
    {
        $this->ownerKey = $this->owner->getPrimaryKey();
        $this->foreignKey = StringHelper :: classBasename($this->owner) . '_id';
    }

    protected function addCriteria()
    {
        $this->query->where($this->foreignKey, $this->owner->{$this->ownerKey});
    }


}