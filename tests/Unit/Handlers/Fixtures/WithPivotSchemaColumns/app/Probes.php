<?php

declare(strict_types=1);

namespace WithPivotFixture;

use WithPivotFixture\Models\Crew;
use WithPivotFixture\Models\Squad;
use WithPivotFixture\Models\Team;

function probes(Team $team, Squad $squad, Crew $crew): void
{
    $_members = $team->members();
    /** @psalm-trace $_members */

    $_tags = $team->tags();
    /** @psalm-trace $_tags */

    $_squad = $squad->members();
    /** @psalm-trace $_squad */

    $_crew = $crew->members();
    /** @psalm-trace $_crew */

    $_admins = $team->adminMemberships();
    /** @psalm-trace $_admins */
}
