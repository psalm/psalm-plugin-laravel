<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Owner of {@see WrappedModel}: a nested eager-load constraint on `wrapped.parts` runs through that model's builder,
 * whose `with()` override keeps its own contract.
 */
final class WrappedParent extends Model
{
    /** @return HasMany<WrappedModel, $this> */
    public function wrapped(): HasMany
    {
        return $this->hasMany(WrappedModel::class);
    }
}
