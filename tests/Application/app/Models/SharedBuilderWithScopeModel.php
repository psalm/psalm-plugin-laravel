<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\SharedSoftScopeBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * No SoftDeletes: its `withTrashed()` is a scope with its own parameter list, on a builder shared with
 * {@see SharedBuilderSoftModel}. That scope's parameters must not leak into any other `withTrashed()` call.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1620
 */
final class SharedBuilderWithScopeModel extends Model
{
    protected $table = 'shared_builder_with_scope_models';

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithTrashed($query, int $mode = 0)
    {
        return $query->where('mode', $mode);
    }

    public function newEloquentBuilder($query): SharedSoftScopeBuilder
    {
        return new SharedSoftScopeBuilder($query);
    }
}
