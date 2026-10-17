<?php

declare(strict_types=1);

namespace PivotShapeFixture\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/** The hydration turns timestamps on from hasTimestampAttributes(); this pivot never does, so its dates stay strings. */
final class NoTimestampsPivot extends Pivot
{
    #[\Override]
    public function hasTimestampAttributes($attributes = null): bool
    {
        return false;
    }
}
