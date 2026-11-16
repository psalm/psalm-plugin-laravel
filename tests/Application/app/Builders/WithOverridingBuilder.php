<?php

declare(strict_types=1);

namespace App\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Builder that overrides `with()`, the method every eager-load entry point ends in, so the contract of an
 * eager-load constraint closure is this builder's, not Laravel's.
 *
 * @template TModel of Model
 * @extends Builder<TModel>
 */
final class WithOverridingBuilder extends Builder
{
    /**
     * @param mixed $relations
     * @param \Closure|string|null $callback
     * @return $this
     */
    #[\Override]
    public function with($relations, $callback = null)
    {
        parent::with($relations, $callback);

        return $this;
    }
}
