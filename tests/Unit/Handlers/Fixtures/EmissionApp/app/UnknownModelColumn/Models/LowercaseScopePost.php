<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class LowercaseScopePost extends Model
{
    protected $table = 'posts';

    /**
     * `hasNamedScope()` uses `method_exists()`, which ignores case, so this runs for `orderBy()`.
     *
     * @param Builder<static> $query
     */
    public function scopeorderby(Builder $query, string $column): void
    {
        $query->join('authors', 'authors.id', '=', 'posts.author_id')->getQuery()->orderBy($column);
    }
}
