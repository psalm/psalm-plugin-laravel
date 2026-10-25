<?php

declare(strict_types=1);

namespace WithPivotFixture\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/** Changes which columns read as cast, so its `$casts` prove nothing. */
class HasCastScore extends Pivot
{
    protected $table = 'scores';

    protected $casts = ['rank' => 'string'];

    #[\Override]
    public function hasCast($key, $types = null)
    {
        return false;
    }
}
