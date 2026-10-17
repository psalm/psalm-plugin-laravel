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

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable')->withPivot('weight');
    }
}
