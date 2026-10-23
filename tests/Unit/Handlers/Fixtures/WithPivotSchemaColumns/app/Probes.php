<?php

declare(strict_types=1);

namespace WithPivotFixture;

use WithPivotFixture\Models\Crew;
use WithPivotFixture\Models\Ledger;
use WithPivotFixture\Models\Odd;
use WithPivotFixture\Models\Post;
use WithPivotFixture\Models\Sheet;
use WithPivotFixture\Models\Squad;
use WithPivotFixture\Models\Team;

function probes(Team $team, Squad $squad, Crew $crew, Ledger $ledger, Sheet $sheet, Odd $odd, Post $post): void
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

    $_entries = $ledger->entries();
    /** @psalm-trace $_entries */

    $_foreign = $team->foreignStamps();
    /** @psalm-trace $_foreign */

    $_plain_stamps = $sheet->plainStamps();
    /** @psalm-trace $_plain_stamps */

    $_plain_stamps_created = $sheet->plainStampsWithCreatedAt();
    /** @psalm-trace $_plain_stamps_created */

    $_odd_default = $odd->defaultStamps();
    /** @psalm-trace $_odd_default */

    $_odd_hours = $odd->stampsWithHours();
    /** @psalm-trace $_odd_hours */

    $_filtered = $team->filteredStamps();
    /** @psalm-trace $_filtered */

    $_delegated_filtered = $team->delegatedFilteredStamps();
    /** @psalm-trace $_delegated_filtered */

    $team->swappedTable();

    $_branched = $team->branchedStamps(true);
    /** @psalm-trace $_branched */

    $_published_at = $post->published_at;
    /** @psalm-trace $_published_at */

    $_reviewed_on = $post->reviewed_on;
    /** @psalm-trace $_reviewed_on */

    $_archived_at = $post->archived_at;
    /** @psalm-trace $_archived_at */

    $_deleted_at = $post->deleted_at;
    /** @psalm-trace $_deleted_at */
}
