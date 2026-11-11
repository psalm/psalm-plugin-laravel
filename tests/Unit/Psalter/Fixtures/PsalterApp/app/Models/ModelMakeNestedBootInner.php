<?php

declare(strict_types=1);

namespace PsalterFixture\Models;

use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Builder;

/**
 * The innermost trait: its `#[Boot]` method reaches every model that uses a trait that uses this one.
 */
trait ModelMakeNestedBootInner
{
    #[Boot]
    protected static function registerNestedScope(): void
    {
        static::addGlobalScope('tenant', static function (Builder $builder): void {
            $builder->where('tenant_id', 7);
        });
    }
}
