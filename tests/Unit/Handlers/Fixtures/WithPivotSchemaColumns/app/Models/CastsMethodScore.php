<?php

declare(strict_types=1);

namespace WithPivotFixture\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/** The `casts()` method is the supported way to declare casts: it keeps the stock hydration. */
class CastsMethodScore extends Pivot
{
    protected $table = 'scores';

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return ['rank' => 'string'];
    }
}
