<?php

declare(strict_types=1);

namespace PivotShapeFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Overrides joiningTable() itself, so the default `rack_user` table is not the one Laravel uses. */
final class Rack extends Model
{
    #[\Override]
    public function joiningTable($related, $instance = null): string
    {
        return 'rack_slots';
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role');
    }
}
