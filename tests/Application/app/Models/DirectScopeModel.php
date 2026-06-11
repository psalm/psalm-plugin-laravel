<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Fixture for psalm/psalm-plugin-laravel#1034.
 *
 * The scope is public so a `.phpt` can perform a direct instance call that passes the
 * $query builder explicitly ($model->hasAnyName($query, ...)). Such a call invokes the
 * real method and must be checked against its full signature, whereas the magic-forwarded
 * forms (DirectScopeModel::hasAnyName(...) / $builder->hasAnyName(...)) strip the leading
 * $query that Laravel injects.
 */
final class DirectScopeModel extends Model
{
    protected $table = 'direct_scope_models';

    /**
     * @param  Builder<self>  $query
     * @param  list<string>  $names
     */
    #[Scope]
    public function hasAnyName(Builder $query, array $names): void
    {
        $query->whereIn('name', $names);
    }
}
