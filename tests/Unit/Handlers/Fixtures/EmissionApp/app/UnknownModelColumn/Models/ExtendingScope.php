<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** `extend()` runs when the scope is registered, so its join survives `withoutGlobalScopes()`. */
final class ExtendingScope implements Scope
{
    /** @param Builder<Model> $builder */
    #[\Override]
    public function apply(Builder $builder, Model $model): void {}

    /** @param Builder<Model> $builder */
    public function extend(Builder $builder): void
    {
        $builder->join('authors', 'authors.id', '=', 'posts.author_id');
    }
}
