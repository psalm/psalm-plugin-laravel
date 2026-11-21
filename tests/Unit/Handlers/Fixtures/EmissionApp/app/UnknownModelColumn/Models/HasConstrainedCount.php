<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

use Illuminate\Database\Eloquent\Builder;

trait HasConstrainedCount
{
    /** A keyed constraint map: the count subselect makes every `$columns` argument dead. */
    public function initializeHasConstrainedCount(): void
    {
        $this->withCount = ['tags' => static fn(Builder $query): Builder => $query->where('label', 'x')];
    }
}
