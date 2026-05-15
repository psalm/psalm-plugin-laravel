<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot recording when one {@see Part} replaced another (e.g., brake pads swapped
 * during a {@see WorkOrder}). Used to exercise `->using(self::class)` on a
 * BelongsToMany — when a pivot model declares its own many-to-many relation and
 * passes itself as the pivot via `using(self::class)`, the parser must substitute
 * the keyword rather than leaking the literal `'self'` into TPivotModel.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/879
 */
final class PartReplacement extends Pivot
{
    protected $table = 'part_replacements';

    /**
     * Other replacements bundled with this one (e.g., "while replacing the brake
     * pads, also replace the rotors"). Modeled as Part-to-Part bundle membership
     * with this pivot acting as itself for the bundle metadata.
     *
     * @psalm-return BelongsToMany<Part, $this, self, 'pivot'>
     */
    public function bundledReplacements(): BelongsToMany
    {
        return $this->belongsToMany(Part::class)->using(self::class);
    }
}
