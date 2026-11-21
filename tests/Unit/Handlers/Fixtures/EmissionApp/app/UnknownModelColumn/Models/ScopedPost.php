<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ScopedPost extends Model
{
    protected $table = 'posts';

    #[\Override]
    protected static function booted(): void
    {
        // The exact H1 shape: a global scope joins another table, so an unqualified `name` is valid SQL.
        static::addGlobalScope('author', static function (Builder $builder): void {
            $builder->join('authors', 'authors.id', '=', 'posts.author_id');
        });
    }
}
