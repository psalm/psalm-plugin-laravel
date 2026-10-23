<?php

declare(strict_types=1);

namespace WithPivotFixture\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/** Converts dates its own way: no column type is proven. */
class OddRota extends Pivot
{
    /** @return list<string> */
    #[\Override]
    public function getDates(): array
    {
        return ['hours'];
    }
}
