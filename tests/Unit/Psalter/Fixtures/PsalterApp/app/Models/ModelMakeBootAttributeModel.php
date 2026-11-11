<?php

declare(strict_types=1);

namespace PsalterFixture\Models;

use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A static method marked `#[Boot]` runs while the model boots, whatever its name: it can register a global scope.
 */
class ModelMakeBootAttributeModel extends Model
{
    #[Boot]
    protected static function registerTenantScope(): void
    {
        static::addGlobalScope('tenant', static function (Builder $builder): void {
            $builder->where('tenant_id', 7);
        });
    }
}
