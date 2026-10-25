<?php

declare(strict_types=1);

namespace DynamicWhereFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Owner extends Model
{
    protected $table = 'owners';

    /** @return HasMany<CustomFilter, $this> */
    public function customFilters(): HasMany
    {
        return $this->hasMany(CustomFilter::class, 'owner_id');
    }

    /** @return HasMany<UserFilter, $this> */
    public function filters(): HasMany
    {
        return $this->hasMany(UserFilter::class, 'owner_id');
    }
}
