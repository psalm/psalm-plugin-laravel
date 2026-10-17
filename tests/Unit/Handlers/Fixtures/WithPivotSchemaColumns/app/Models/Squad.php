<?php

declare(strict_types=1);

namespace WithPivotFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Joins `team_user` at runtime, but only through an overridden segment the plugin does not evaluate. */
class Squad extends Model
{
    public function joiningTableSegment(): string
    {
        return 'team';
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role');
    }
}
