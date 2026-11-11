<?php

declare(strict_types=1);

namespace PsalterFixture\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class ModelMakeTenantScope implements Scope
{
    /**
     * @param Builder<Model> $builder
     */
    #[\Override]
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where('tenant_id', 7);
    }
}
