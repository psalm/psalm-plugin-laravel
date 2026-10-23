<?php

declare(strict_types=1);

namespace WithPivotFixture;

use WithPivotFixture\Models\Crew;
use WithPivotFixture\Models\Ledger;
use WithPivotFixture\Models\Odd;
use WithPivotFixture\Models\Sheet;
use WithPivotFixture\Models\Squad;
use WithPivotFixture\Models\Team;

function probes(Team $team, Squad $squad, Crew $crew, Ledger $ledger, Sheet $sheet, Odd $odd): void
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

    $_scored = $team->scored();
    /** @psalm-trace $_scored */

    $_scored_last = $team->scoredUsingLast();
    /** @psalm-trace $_scored_last */

    $_scored_stamps = $team->scoredStamps();
    /** @psalm-trace $_scored_stamps */

    $_scored_unstamped = $team->scoredUnstamped();
    /** @psalm-trace $_scored_unstamped */

    $_plain_scored = $team->plainScored();
    /** @psalm-trace $_plain_scored */

    $_dated_scored = $team->datedScored();
    /** @psalm-trace $_dated_scored */

    $_converting_scored = $team->convertingScored();
    /** @psalm-trace $_converting_scored */

    $_hidden_scored = $team->hiddenScored();
    /** @psalm-trace $_hidden_scored */

    $_foreign_scored = $team->foreignScored();
    /** @psalm-trace $_foreign_scored */

    $_delegated_scored = $team->delegatedScored();
    /** @psalm-trace $_delegated_scored */

    $_branched_scored = $team->branchedScored(true);
    /** @psalm-trace $_branched_scored */

    $_raw_entries = $sheet->rawEntries();
    /** @psalm-trace $_raw_entries */

    $_delegated_hidden_scored = $team->delegatedHiddenScored();
    /** @psalm-trace $_delegated_hidden_scored */

    $_raw_scored = $team->rawScored();
    /** @psalm-trace $_raw_scored */
}
