<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Owner of {@see CombiningModel}, for dot paths through its builder that overrides `combineConstraints()`. */
final class CombiningOwner extends Model
{
    /** @return HasMany<CombiningModel, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(CombiningModel::class);
    }
}
