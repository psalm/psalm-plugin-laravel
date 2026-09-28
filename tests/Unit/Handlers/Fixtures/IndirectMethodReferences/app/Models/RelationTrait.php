<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait RelationTrait
{
    public function traitTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
