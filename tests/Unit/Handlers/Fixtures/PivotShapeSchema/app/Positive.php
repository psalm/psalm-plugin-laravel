<?php

declare(strict_types=1);

namespace PivotShapeFixture;

use Illuminate\Database\Eloquent\Relations\Pivot;
use PivotShapeFixture\Models\Squad;
use PivotShapeFixture\Models\Team;

/**
 * Every `psalm-trace` here is pinned by BelongsToManyPivotShapeSchemaEmissionTest, in order. `@psalm-trace`
 * rather than `@psalm-check-type-exact`: the exact check compares object shapes loosely (mutual containment),
 * so it cannot tell `level: int` from `level: string`. The schema (`database/migrations`) is what the
 * type-test harness cannot provide.
 */
function issue_repro(Team $team): string
{
    $relation = $team->members();
    /** @psalm-trace $relation */

    $pivot = $relation->firstOrFail()->pivot;
    /** @psalm-trace $pivot */

    return $pivot->role;
}

/** @param Pivot&object{role: string} $pivot */
function declared_shape_fetch(Pivot $pivot): string
{
    return $pivot->role;
}

function schema_types(Team $team): void
{
    $relation = $team->byConvention();
    /** @psalm-trace $relation */
    echo \get_debug_type($relation);
}

function declared_fallback_and_schema_precedence(Team $team): void
{
    $relation = $team->declaredFallback();
    /** @psalm-trace $relation */
    echo \get_debug_type($relation);
}

function dynamic_table_is_mixed(Team $team): void
{
    $relation = $team->dynamicTable('team_user');
    /** @psalm-trace $relation */
    echo \get_debug_type($relation);
}

function table_from_model(Team $team): void
{
    $relation = $team->tableFromModel();
    /** @psalm-trace $relation */
    echo \get_debug_type($relation);
}

function delegation_keeps_inner_and_outer_columns(Team $team): void
{
    $relation = $team->delegated();
    /** @psalm-trace $relation */
    echo \get_debug_type($relation);
}

function timestamps_follow_the_base_pivot_casts(Team $team): void
{
    $stock = $team->stockTimestamps();
    /** @psalm-trace $stock */
    echo \get_debug_type($stock);

    // Created-at is renamed, so the parent's created-at column is not selected and nothing is date-cast.
    $renamed = $team->renamedTimestamp();
    /** @psalm-trace $renamed */
    echo \get_debug_type($renamed);
}

function custom_pivot_class_wins(Team $team): void
{
    $relation = $team->assigned();
    /** @psalm-trace $relation */
    echo \get_debug_type($relation);
}

function morph_to_many_table_is_pluralized(Team $team): void
{
    $relation = $team->tags();
    /** @psalm-trace $relation */
    echo \get_debug_type($relation);
}

function overridden_related_segment_is_mixed(Team $team): void
{
    $relation = $team->overriddenRelated();
    /** @psalm-trace $relation */
    echo \get_debug_type($relation);
}

function overridden_joining_table_segment_is_mixed(Squad $squad): void
{
    $relation = $squad->overriddenSegment();
    /** @psalm-trace $relation */
    echo \get_debug_type($relation);
}
