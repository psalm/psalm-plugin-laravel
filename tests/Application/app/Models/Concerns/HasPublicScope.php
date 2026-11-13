<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * A public `#[Scope]` in a trait, for models that narrow it with `use HasPublicScope { published as protected; }`.
 *
 * @psalm-require-extends \Illuminate\Database\Eloquent\Model
 */
trait HasPublicScope
{
    /** @param Builder<self> $query */
    #[Scope]
    public function published(Builder $query): void
    {
        $query->where('published', true);
    }
}
