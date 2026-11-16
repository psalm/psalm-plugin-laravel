<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\WithOverridingBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `newEloquentBuilder()` with a docblock-only return type: the plugin's detector needs a native named return type,
 * so the real builder (here one that overrides `with()`) is unknown.
 */
final class DocblockBuilderChild extends Model
{
    /**
     * @param \Illuminate\Database\Query\Builder $query
     * @return WithOverridingBuilder<static>
     */
    #[\Override]
    public function newEloquentBuilder($query)
    {
        /** @var WithOverridingBuilder<DocblockBuilderChild> $builder */
        $builder = new WithOverridingBuilder($query);

        return $builder;
    }

    /** @return HasMany<DocblockBuilderGrandchild, $this> */
    public function grandchildren(): HasMany
    {
        return $this->hasMany(DocblockBuilderGrandchild::class);
    }
}
