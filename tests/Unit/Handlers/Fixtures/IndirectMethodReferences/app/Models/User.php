<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class User extends BaseUser
{
    use RelationTrait;

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    protected function privateTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function ordinaryRelation(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function ordinaryUnused(): void {}
}
