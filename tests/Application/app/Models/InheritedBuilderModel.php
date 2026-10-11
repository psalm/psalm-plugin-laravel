<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\InheritedModelBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Non-final base model whose generic custom builder (via `newEloquentBuilder()`) is inherited by
 * {@see InheritedBuilderChild}. Archetype for one builder class mapped to several models: scopes
 * and SoftDeletes methods called on `InheritedBuilderModel::query()` must keep resolving to
 * `InheritedModelBuilder<InheritedBuilderModel>` rather than to the descendant.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1620
 */
class InheritedBuilderModel extends Model
{
    use SoftDeletes;

    protected $table = 'inherited_builder_models';

    /**
     * Legacy scope.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisible($query)
    {
        return $query->where('visible', true);
    }

    public function newEloquentBuilder($query): InheritedModelBuilder
    {
        return new InheritedModelBuilder($query);
    }
}
