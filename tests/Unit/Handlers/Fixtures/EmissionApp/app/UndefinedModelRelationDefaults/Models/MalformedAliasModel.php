<?php

declare(strict_types=1);

namespace RelationDefaultsFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MalformedAliasModel extends Model
{
    protected $withCount = ['comments  AS  total'];

    /** @return HasMany<RelatedModel, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(RelatedModel::class);
    }
}
