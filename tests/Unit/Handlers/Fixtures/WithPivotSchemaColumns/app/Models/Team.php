<?php

declare(strict_types=1);

namespace WithPivotFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

class Team extends Model
{
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role', 'note', 'rank', 'missing', 'created_at');
    }

    public function memberships(): BelongsToMany
    {
        $relation = $this->belongsToMany(User::class, 'memberships')->withPivot('admin');
        /** @psalm-trace $relation */

        return $relation;
    }

    public function baseMemberships(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships');
    }

    /** Delegation: the columns are typed from baseMemberships()'s table. */
    public function adminMemberships(): BelongsToMany
    {
        return $this->baseMemberships()->withPivot('admin');
    }

    public function stamped(): BelongsToMany
    {
        $relation = $this->belongsToMany(User::class, 'memberships_stamped')->withPivot('role')->withTimestamps();
        /** @psalm-trace $relation */

        return $relation;
    }

    /** The custom pivot's `@property` and cast win; the rest is the relation's table, dates included. */
    public function assignments(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'staff_assignments')->using(Assignment::class)->withPivot('hours', 'label', 'level', 'note', 'grade')->withTimestamps();
    }

    /** No `@property`, no casts: the timestamp columns are dates, not their raw schema type. */
    public function rota(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'staff_assignments')->using(Rota::class)->withPivot('hours')->withTimestamps();
    }

    public function rotaUsingFirst(): BelongsToMany
    {
        $relation = $this->belongsToMany(User::class, 'staff_assignments')->withTimestamps()->withPivot('hours')->using(Rota::class);
        /** @psalm-trace $relation */

        return $relation;
    }

    /** Laravel reads the timestamp names off the factory's receiver (a Sheet), not off this model. */
    public function foreignStamps(): BelongsToMany
    {
        return (new Sheet())->belongsToMany(User::class, 'sheet_entries')->withTimestamps();
    }

    public function oddRota(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'staff_assignments')->using(OddRota::class)->withPivot('hours')->withTimestamps();
    }

    /** @param class-string<Pivot> $pivotClass */
    public function dynamicUsing(string $pivotClass): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'staff_assignments')->using($pivotClass)->withPivot('hours')->withTimestamps();
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->withPivot('weight');
    }
}
