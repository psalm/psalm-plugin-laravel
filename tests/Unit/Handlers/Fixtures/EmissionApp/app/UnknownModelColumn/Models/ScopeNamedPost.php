<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ScopeNamedPost extends Model
{
    protected $table = 'posts';

    /**
     * Runs instead of Query\Builder::orderBy() (Eloquent\Builder::__call checks scopes first).
     *
     * @param Builder<static> $query
     */
    public function scopeOrderBy(Builder $query, string $column): void
    {
        $query->join('authors', 'authors.id', '=', 'posts.author_id')->orderBy($column);
    }
}
