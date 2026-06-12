<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Trait-hosted scopes: both legacy `scopeXxx` and `#[Scope]`-attributed forms,
 * exercising the two code paths that must resolve when a scope lives in a trait
 * rather than on the model class itself.
 *
 * @psalm-require-extends \Illuminate\Database\Eloquent\Model
 */
trait HasFlaggedScope
{
    /** @param Builder<self> $query */
    public function scopeFlagged(Builder $query): void
    {
        $query->where('flagged', true);
    }

    /**
     * Attributed scope in a trait: verifies that #[Scope]-annotated methods are
     * detected when they live in a trait rather than directly on the model class.
     * Both base-Builder and custom-Builder surfaces must resolve this call.
     *
     * @param Builder<self> $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('active', true);
    }
}
