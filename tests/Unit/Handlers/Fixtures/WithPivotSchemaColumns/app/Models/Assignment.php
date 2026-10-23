<?php

declare(strict_types=1);

namespace WithPivotFixture\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A custom pivot that describes some columns itself. Its own table differs from the relation's:
 * the columns it does not describe read from the relation's table, not from this one. `hours` and
 * `level` exist in that default table, so the pivot class's own property types answer reads of them.
 *
 * @property non-empty-string $label
 */
class Assignment extends Pivot
{
    protected $table = 'assignment_defaults';

    /** @var array<string, string> */
    protected $casts = ['level' => 'integer', 'grade' => 'integer'];
}
