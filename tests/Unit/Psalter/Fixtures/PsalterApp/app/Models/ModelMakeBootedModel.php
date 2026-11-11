<?php

declare(strict_types=1);

namespace PsalterFixture\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Registers a global scope in `booted()`: a scope's `extend()` can add pending attributes that `make()` applies
 * and `new` does not.
 */
class ModelMakeBootedModel extends Model
{
    #[\Override]
    protected static function booted(): void
    {
        static::addGlobalScope('tenant', static function (Builder $builder): void {
            $builder->where('tenant_id', 7);
        });
    }
}
