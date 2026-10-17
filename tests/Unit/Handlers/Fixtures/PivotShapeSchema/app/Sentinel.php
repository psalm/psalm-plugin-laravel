<?php

declare(strict_types=1);

namespace PivotShapeFixture;

use PivotShapeFixture\Models\Team;

/**
 * The deliberately wrong assertion proves the run reports CheckType at all: without it a harness that
 * silently stopped analysing the fixture would pass every positive check.
 */
function sentinel(Team $team): void
{
    $relation = $team->members();
    /** @psalm-check-type-exact $relation = Team */
    echo \get_debug_type($relation);
}
