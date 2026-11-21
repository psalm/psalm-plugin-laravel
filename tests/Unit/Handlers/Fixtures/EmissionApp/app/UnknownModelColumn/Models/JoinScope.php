<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

final class JoinScope implements Scope
{
    #[\Override]
    public function apply(Builder $builder, Model $model): void
    {
        $builder->join('authors', 'authors.id', '=', 'posts.author_id');
    }
}
