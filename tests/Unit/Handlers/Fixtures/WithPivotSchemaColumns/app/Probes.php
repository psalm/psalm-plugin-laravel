<?php

declare(strict_types=1);

namespace WithPivotFixture;

use WithPivotFixture\Models\Squad;
use WithPivotFixture\Models\Team;

function probes(Team $team, Squad $squad): void
{
    $_members = $team->members();
    /** @psalm-trace $_members */

    $_tags = $team->tags();
    /** @psalm-trace $_tags */

    $_squad = $squad->members();
    /** @psalm-trace $_squad */
}
