<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BaseUser extends Model
{
    public function baseTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
