<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;

/**
 * Concrete descendant of {@see InheritedBuilderModel} that inherits its custom builder; it must not
 * displace the base model when the builder's scope and SoftDeletes methods are resolved. It also
 * overrides `scopeVisible()` with an extra optional parameter and is the only model declaring
 * `scopeCount()`. Scope params are receiver-less, so the override's signature also applies to base
 * receivers (an accepted false negative); `scopeCount()` must not affect a base receiver's native `count()`.
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
     * Child-only fluent scope named like a native aggregate (`Builder::__call` consults scopes on the
     * actual model only, so `InheritedBuilderModel::query()->count()` stays the int aggregate).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCount($query, int $limit = 0)
    {
        return $query->where('counted', true);
    }
}
