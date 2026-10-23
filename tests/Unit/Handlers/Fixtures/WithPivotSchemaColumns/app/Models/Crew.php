<?php

declare(strict_types=1);

namespace WithPivotFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Joins `memberships`, not the conventional `crew_user`, through an overridden joiningTable(). */
class Crew extends Model
{
    #[\Override]
    public function joiningTable($related, $instance = null)
    {
        return 'memberships';
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('admin');
    }
}
