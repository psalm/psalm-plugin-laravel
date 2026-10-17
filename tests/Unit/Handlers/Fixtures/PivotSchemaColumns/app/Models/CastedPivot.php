<?php

declare(strict_types=1);

namespace PivotSchemaFixture\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * No `@property` docblock: the read types come from the casts (and the column nullability in the migration).
 */
final class CastedPivot extends Pivot
{
    protected $table = 'membership_details';

    /** @var array<string, string> */
    protected $casts = [
        'status' => MembershipStatus::class,
        'is_active' => 'boolean',
        'approved_at' => 'datetime',
        'meta' => 'array',
    ];
}
