<?php

declare(strict_types=1);

namespace WithPivotFixture\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/** Moves the date columns, so no raw column type of the relation table is proven. */
class DatedScore extends Pivot
{
    /** @return list<string> */
    #[\Override]
    public function getDates(): array
    {
        return ['seen_at'];
    }
}
