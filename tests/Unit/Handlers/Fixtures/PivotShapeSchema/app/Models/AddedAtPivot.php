<?php

declare(strict_types=1);

namespace PivotShapeFixture\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/** Converts `added_at` to a date: the raw `team_user` schema type of that column (and of every other) is not the read type. */
final class AddedAtPivot extends Pivot
{
    /** @return list<string> */
    #[\Override]
    public function getDates(): array
    {
        return ['added_at'];
    }
}
