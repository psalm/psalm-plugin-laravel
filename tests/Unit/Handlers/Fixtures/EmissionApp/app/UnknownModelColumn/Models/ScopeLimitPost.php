<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ScopeLimitPost extends Model
{
    protected $table = 'posts';

    /**
     * Runs for the `limit()` call inside `first()` (`Builder::__call` checks scopes first).
     *
     * @param Builder<static> $query
     */
    public function scopeLimit(Builder $query, int $value): void
    {
        $query->getQuery()->limit($value)->join('authors', 'authors.id', '=', 'posts.author_id');
    }
}
