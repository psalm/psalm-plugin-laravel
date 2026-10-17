<?php

declare(strict_types=1);

namespace PivotSchemaFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * No `@property` docblock: every pivot column type is known only from the migration.
 *
 * Every relation method below must analyse without InvalidReturnType/InvalidReturnStatement
 * (#1944 point 1), and the traced chains / call sites must carry the schema-typed pivot shape.
 */
class Team extends Model
{
    protected $table = 'teams';

    // NO docblock at all. The default joining table (`team_user`) is resolved from the two
    // model class names, and the columns come from the parsed body chain.
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role', 'level')->withTimestamps();
    }

    // Explicit literal table name, plain 2-param declaration (a refined pivot must still satisfy it).
    /** @return BelongsToMany<User, $this> */
    public function explicitTable(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')->withPivot('role');
    }

    // A custom-named table that no naming convention would produce.
    /** @return BelongsToMany<User, $this, Pivot, 'pivot'> */
    public function memberships(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_memberships')->withPivot('title', 'is_owner');
    }

    // morphToMany default table (`taggables`) and a MorphPivot slot.
    /** @return MorphToMany<Tag, $this, MorphPivot, 'pivot'> */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->withPivot('note');
    }

    // Order-independent with using(); the pivot class is the custom one.
    /** @return BelongsToMany<User, $this, TeamUserPivot, 'pivot'> */
    public function usingPivot(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->using(TeamUserPivot::class)->withPivot('role');
    }

    // The declared return type carries an object shape on its pivot slot, so the declared shape
    // (`role` only) wins at call sites even though the body also selects `level`.
    /** @return BelongsToMany<User, $this, Pivot&object{role: string}, 'pivot'> */
    public function shaped(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role', 'level');
    }

    // A using() pivot with its own casts (backed enum, boolean, datetime, array): the cast types win over the
    // raw schema types, and the nullable `meta` column keeps its `|null`.
    public function castedMembers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'membership_details')
            ->using(CastedPivot::class)
            ->withPivot('status', 'is_active', 'approved_at', 'meta');
    }

    // In-body chain types. The pivot table is resolved from the factory call of the chain itself.
    public function traceInBody(): void
    {
        $inBodyDefault = $this->belongsToMany(User::class)->withPivot('role', 'level')->withTimestamps();
        /** @psalm-trace $inBodyDefault */
        $inBodyExplicit = $this->belongsToMany(User::class, 'team_user')->withPivot('role');
        /** @psalm-trace $inBodyExplicit */
        $inBodyCustom = $this->belongsToMany(User::class, 'team_memberships')->withPivot('title', 'is_owner');
        /** @psalm-trace $inBodyCustom */
        $inBodyMorph = $this->morphToMany(Tag::class, 'taggable')->withPivot('note');
        /** @psalm-trace $inBodyMorph */
        $inBodyUsing = $this->belongsToMany(User::class)->using(TeamUserPivot::class)->withPivot('role');
        /** @psalm-trace $inBodyUsing */

        return;
    }
}
