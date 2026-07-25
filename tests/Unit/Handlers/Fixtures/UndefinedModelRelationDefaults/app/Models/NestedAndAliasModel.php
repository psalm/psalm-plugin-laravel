<?php

declare(strict_types=1);

namespace RelationDefaultsFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class NestedAndAliasModel extends Model
{
    protected $with = ['related:nestedRelation', 'related.nestedRelation'];

    protected $withCount = ['related AS related_total'];

    /** @return HasMany<RelatedModel, $this> */
    public function related(): HasMany
    {
        return $this->hasMany(RelatedModel::class);
    }
}
