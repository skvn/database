<?php

namespace Skvn\Database\Relations;

use Skvn\Base\Helpers\Str;

class BelongsTo extends Relation
{

    protected function defaultLink()
    {
        $this->ownerKey = Str :: classBasename($this->related) . '_id';
        $this->foreignKey = $this->related->getPrimaryKeyName();
    }

    protected function addCriteria()
    {
        $this->query->where($this->foreignKey, $this->owner->{$this->ownerKey});
    }


}