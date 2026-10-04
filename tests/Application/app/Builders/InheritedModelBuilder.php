<?php

declare(strict_types=1);

namespace App\Builders;

use App\Models\InheritedBuilderModel;
use Illuminate\Database\Eloquent\Builder;

/**
 * Generic custom builder shared by {@see InheritedBuilderModel} and its descendants (children
 * inherit `newEloquentBuilder()`), so one builder class maps to several models.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1620
 *
 * @template TModel of InheritedBuilderModel
 *
 * @extends Builder<TModel>
 */
class InheritedModelBuilder extends Builder
{
    /**
     * Own fluent method with `@return static`, so a chain continues on the receiver's builder type.
     */
    public function active(): static
    {
        return $this->where('active', true);
    }
}
