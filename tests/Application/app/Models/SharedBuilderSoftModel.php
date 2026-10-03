<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\SharedSoftScopeBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * SoftDeletes model on {@see SharedSoftScopeBuilder}: `withTrashed()` resolves through the builder's
 * trait-method params provider, which answers for the shared builder before the scope params provider.
 * Named to register before {@see SharedBuilderWithScopeModel}, which fixes that provider order.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1620
 */
final class SharedBuilderSoftModel extends Model
{
    use SoftDeletes;

    protected $table = 'shared_builder_soft_models';

    public function newEloquentBuilder($query): SharedSoftScopeBuilder
    {
        return new SharedSoftScopeBuilder($query);
    }
}
