<?php

declare(strict_types=1);

namespace PivotShapeFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

final class Team extends Model
{
    /**
     * The issue's relation: the declared shape matches what the body infers, and `team_user.role` is a string.
     *
     * @return BelongsToMany<User, self, Pivot&object{role: string}, 'pivot'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /** No table argument: `team_user`, the sorted snake_cased basenames. Schema types win over nothing declared. */
    public function byConvention(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('level', 'notes', 'kind', 'unknown_column');
    }

    /**
     * A column the schema lacks takes the declared type; a column it has keeps the schema type, not the
     * declared one (`level` is an unsigned integer).
     *
     * @return BelongsToMany<User, self, Pivot&object{extra: float, level: string}, 'pivot'>
     */
    public function declaredFallback(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('extra', 'level');
    }

    /** The related model overrides joiningTableSegment(): Laravel's table is `roost_team`, not `perch_team`. */
    public function overriddenRelated(): BelongsToMany
    {
        return $this->belongsToMany(Perch::class)->withPivot('seat');
    }

    /** The table is not a literal: nothing to look the schema up in. */
    public function dynamicTable(string $table): BelongsToMany
    {
        return $this->belongsToMany(User::class, $table)->withPivot('role');
    }

    /** A model passed as the table: its registered schema types the columns. */
    public function tableFromModel(): BelongsToMany
    {
        return $this->belongsToMany(User::class, Membership::class)->withPivot('seat');
    }

    /** Delegation: the delegated relation's columns first, then this chain's. */
    public function delegated(): BelongsToMany
    {
        return $this->byConvention()->withPivot('score');
    }

    /** Renamed timestamp columns are plain schema columns: `added_at` is not the parent's created-at. */
    public function renamedTimestamp(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps('added_at');
    }

    public function stockTimestamps(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /** A custom pivot class wins over the schema for the columns it declares. */
    public function assigned(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')->using(RoleAssignment::class)->withPivot('level', 'role');
    }

    /** morphToMany() pluralizes the morph name: `taggables`. */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->withPivot('weight')->withTimestamps();
    }

    /** Only the columns every branch selects (from the same table) survive. */
    public function branched(bool $flag): BelongsToMany
    {
        if ($flag) {
            return $this->belongsToMany(User::class)->withPivot('role', 'level');
        }

        return $this->belongsToMany(User::class)->withPivot('level', 'notes');
    }

    /** using() resets the shape: the custom class describes its own columns. */
    public function usingResets(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')->withPivot('role')->using(RoleAssignment::class);
    }

    /** A custom pivot with unproven casts leaves the column unclaimed. */
    public function partialCasts(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')->using(PartialPivot::class)->withPivot('role');
    }

    /** `using()` hydrates through fromRawAttributes(): the parent's created-at column switches the casts on. */
    public function customTimestamps(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')->using(RoleAssignment::class)->withTimestamps();
    }

    /**
     * A template inside the declared shape is not a type to reuse.
     *
     * @template T of object
     * @return BelongsToMany<User, self, Pivot&object{nested: list<T>, label: string}, 'pivot'>
     */
    public function templatedFallback(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('nested', 'label');
    }

    /**
     * A variable may hold the array Laravel reads instead of the trailing columns.
     *
     * @param list<string> $columns
     */
    public function variableFirstArgument(array $columns): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot($columns, 'role');
    }

    /** The created-at switch is selected on one branch only: whether `updated_at` is a date depends on the branch. */
    public function branchedSwitch(bool $flag): BelongsToMany
    {
        if ($flag) {
            return $this->belongsToMany(User::class, 'team_user')->withPivot('updated_at', 'created_at', 'level');
        }

        return $this->belongsToMany(User::class, 'team_user')->withPivot('updated_at', 'level');
    }

    /** withTimestamps() on one branch only. */
    public function branchedTimestamps(bool $flag): BelongsToMany
    {
        if ($flag) {
            return $this->belongsToMany(User::class, 'team_user')->withPivot('updated_at')->withTimestamps();
        }

        return $this->belongsToMany(User::class, 'team_user')->withPivot('updated_at');
    }

    /** A custom pivot that overrides getDates() does not convert the dates. */
    public function manualDates(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')->using(ManualDatesPivot::class)->withTimestamps();
    }

    /** A custom pivot overriding hasTimestampAttributes() never switches its timestamps on. */
    public function noTimestamps(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')->using(NoTimestampsPivot::class)->withTimestamps();
    }

    /** A later string key replaces an earlier one in array_merge(): keyed entries claim no column. */
    public function keyedArrays(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')->withPivot(['k' => 'role'], 'level')->withPivot(['k' => 'level', 'notes']);
    }

    /** The class converts `added_at` itself: nothing but its own declarations is proven. */
    public function nonStockDates(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')->using(AddedAtPivot::class)->withPivot('added_at', 'level');
    }

    /** A stock parent: both hydration rules agree on `created_at` / `updated_at`, so the hidden chain does not matter. */
    public function heldStock(): BelongsToMany
    {
        $relation = $this->belongsToMany(User::class, 'team_user')->using(Pivot::class);

        $result = $relation->withTimestamps();
        /** @psalm-trace $result */

        return $result;
    }

    /** A string-keyed entry may select `created_at`, which switches the date casts on: `updated_at` is then unknown. */
    public function keyedSwitch(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')->withPivot(['k' => 'created_at', 'updated_at']);
    }

    /** The same split across calls. */
    public function keyedSwitchSplit(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')->withPivot(['k' => 'created_at'])->withPivot('updated_at');
    }

    /** Explicit integer keys are list entries: both columns are claimed. */
    public function integerKeys(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')->withPivot([0 => 'level', 'notes']);
    }

    public function keyedSwitchInBody(): void
    {
        $relation = $this->belongsToMany(User::class, 'team_user')->withPivot(['k' => 'created_at', 'updated_at']);
        /** @psalm-trace $relation */
        echo \get_debug_type($relation);

        $split = $this->belongsToMany(User::class, 'team_user')->withPivot(['k' => 'created_at'])->withPivot('updated_at');
        /** @psalm-trace $split */
        echo \get_debug_type($split);
    }

    /** A non-literal key may overwrite an int-keyed entry before array_merge() sees it: nothing is claimed. */
    public function dynamicKey(string $key): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')->withPivot([0 => 'level', $key => 'notes'], 'role');
    }

    public function laterKeyedCall(): void
    {
        // The earlier shape already holds `updated_at`; the keyed call may select created_at and switch the casts on.
        $relation = $this->belongsToMany(User::class, 'team_user')->withPivot('updated_at')->withPivot(['k' => 'created_at']);
        /** @psalm-trace $relation */
        echo \get_debug_type($relation);
    }
}
