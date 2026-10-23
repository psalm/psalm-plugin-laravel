<?php

declare(strict_types=1);

namespace WithPivotFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

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

    /** Laravel reads the timestamp names off the factory's receiver (a Sheet), not off this model. */
    public function foreignStamps(): BelongsToMany
    {
        return (new Sheet())->belongsToMany(User::class, 'sheet_entries')->withTimestamps();
    }

    /** A `where()` between the factory and `withTimestamps()` may be anything, so no date is proven. */
    public function filteredStamps(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships_stamped')->where('role', 'admin')->withTimestamps();
    }

    public function stampedBody(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'memberships_stamped')->withPivot('role')->withTimestamps();
    }

    /** The outer `where()` is not known to keep the pivot class either: the delegated chain is not visible. */
    public function delegatedFilteredStamps(): BelongsToMany
    {
        return $this->stampedBody()->where('role', 'admin');
    }

    /** One branch is another model's factory, which takes that model's timestamp names: no shape on either. */
    public function branchedStamps(bool $foreign): BelongsToMany
    {
        return $this->branchedBase($foreign)->withTimestamps();
    }

    private function branchedBase(bool $foreign): BelongsToMany
    {
        if ($foreign) {
            return (new Sheet())->belongsToMany(User::class, 'sheet_entries');
        }

        return $this->belongsToMany(User::class, 'sheet_entries');
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->withPivot('weight');
    }
}
