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
}
