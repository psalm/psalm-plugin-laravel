<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * Concrete descendant of {@see InheritedBuilderModel} that inherits its custom builder, so one builder
 * class maps to two models. It overrides `scopeVisible()` with an extra optional parameter and alone
 * declares `scopeChildOnly()`.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1620
 */
final class InheritedBuilderChild extends InheritedBuilderModel
{
    /**
     * Overrides the base scope with an additional optional parameter.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[\Override]
    public function scopeVisible($query, bool $extra = false)
    {
        return $query->where('visible', true);
    }

    /**
     * Child-only fluent scope.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeChildOnly($query)
    {
        return $query->where('child_only', true);
    }
}
