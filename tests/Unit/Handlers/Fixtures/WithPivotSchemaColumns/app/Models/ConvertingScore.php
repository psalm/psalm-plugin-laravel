<?php

declare(strict_types=1);

namespace WithPivotFixture\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/** Converts dates its own way, so no raw column type of the relation table is proven. */
class ConvertingScore extends Pivot
{
    #[\Override]
    protected function asDateTime($value)
    {
        return parent::asDateTime($value);
    }
}
