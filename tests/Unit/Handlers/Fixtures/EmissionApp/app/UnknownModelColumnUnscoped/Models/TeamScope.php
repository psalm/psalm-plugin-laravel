<?php

declare(strict_types=1);

namespace UnscopedColumnFixture\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** A plain where scope with no `extend()`: removing it leaves the builder untouched. */
final class TeamScope implements Scope
{
    /** @param Builder<Model> $builder */
    #[\Override]
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where('author_id', 1);
    }
}
