<?php

declare(strict_types=1);

namespace UnscopedColumnFixture;

use UnscopedColumnFixture\Models\TeamPost;
use UnscopedColumnFixture\Models\TeamScope;

/**
 * Analyzed in its own Psalm run, where no scope declares `extend()`: a bare `withoutGlobalScopes()`
 * then drops the trait's scope and the column list is checked.
 */
function unscoped_reads(): void
{
    TeamPost::withoutGlobalScopes()->where('title', 'x')->latest()->take(10)->get(['title', 'typocol_unscoped_team']);
    TeamPost::query()->get(['silentcol_team_scoped']);
    TeamPost::withoutGlobalScopes([TeamScope::class])->get(['silentcol_scopes_named']);
    TeamPost::withoutGlobalScope(TeamScope::class)->get(['silentcol_scope_named']);
}
