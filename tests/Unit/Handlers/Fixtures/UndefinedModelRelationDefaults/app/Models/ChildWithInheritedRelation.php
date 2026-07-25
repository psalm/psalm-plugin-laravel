<?php

declare(strict_types=1);

namespace RelationDefaultsFixture\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

final class ChildWithInheritedRelation extends AbstractDefaultsModel
{
    /** @return HasMany<RelatedModel, $this> */
    public function childRelation(): HasMany
    {
        return $this->hasMany(RelatedModel::class);
    }
}
