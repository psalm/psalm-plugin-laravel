<?php

declare(strict_types=1);

namespace RelationDefaultsFixture\Concerns;

use RelationDefaultsFixture\Models\RelatedModel;

trait HasTraitRelation
{
    public function traitRelation()
    {
        return $this->hasMany(RelatedModel::class);
    }
}
