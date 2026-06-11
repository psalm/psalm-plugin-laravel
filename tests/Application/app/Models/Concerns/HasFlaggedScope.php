<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Trait-hosted scope: instance scope calls must resolve when the scope lives
 * in a trait rather than on the model class itself.
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
}
