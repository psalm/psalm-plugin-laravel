<?php

declare(strict_types=1);

namespace WithPivotFixture\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A custom pivot for `team_scores`. Its own table lacks `flags` and `rank`, so their casts apply to the
 * relation table's columns, and `score` has an accessor that must win over the column's raw string type.
 */
class Score extends Pivot
{
    protected $table = 'scores';

    protected $casts = ['flags' => 'array', 'rank' => 'string'];

    protected function getScoreAttribute(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
