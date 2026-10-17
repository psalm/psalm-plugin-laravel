<?php

declare(strict_types=1);

namespace PivotShapeFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Overrides the joining table segment, so the default pivot table cannot be derived statically. */
final class Squad extends Model
{
    #[\Override]
    public function joiningTableSegment(): string
    {
        return 'seat';
    }

    public function overriddenSegment(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('seat');
    }
}
