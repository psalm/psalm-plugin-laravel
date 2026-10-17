<?php

declare(strict_types=1);

namespace PivotSchemaFixture;

use PivotSchemaFixture\Models\NoCreatedAtTeam;
use PivotSchemaFixture\Models\SpecialTeam;
use PivotSchemaFixture\Models\StampedTeam;
use PivotSchemaFixture\Models\Team;

/**
 * Call-site typing through the model method (ModelRelationReturnTypeHandler): the pivot shape is rebuilt
 * from the parsed relation body and typed from the migration schema. `@psalm-trace` messages are asserted
 * by PivotSchemaColumnsEmissionTest.
 */
function call_sites(Team $team): void
{
    $siteMembers = $team->members();
    /** @psalm-trace $siteMembers */
    $siteExplicit = $team->explicitTable();
    /** @psalm-trace $siteExplicit */
    $siteMemberships = $team->memberships();
    /** @psalm-trace $siteMemberships */
    $siteTags = $team->tags();
    /** @psalm-trace $siteTags */
    $siteUsing = $team->usingPivot();
    /** @psalm-trace $siteUsing */
    $siteShaped = $team->shaped();
    /** @psalm-trace $siteShaped */

    return;
}

function call_sites_timestamp_columns(StampedTeam $stamped, NoCreatedAtTeam $noCreatedAt): void
{
    $siteStamped = $stamped->members();
    /** @psalm-trace $siteStamped */
    $siteStampedUsing = $stamped->customMembers();
    /** @psalm-trace $siteStampedUsing */
    $siteNoCreatedAt = $noCreatedAt->members();
    /** @psalm-trace $siteNoCreatedAt */

    return;
}

function call_sites_custom_pivot_casts(Team $team): void
{
    $siteCasted = $team->castedMembers();
    /** @psalm-trace $siteCasted */

    return;
}

// `members()` is declared on Team without a table argument; the default joining table follows the receiver.
function call_sites_inherited_relation(SpecialTeam $special): void
{
    $siteSpecial = $special->members();
    /** @psalm-trace $siteSpecial */

    return;
}
