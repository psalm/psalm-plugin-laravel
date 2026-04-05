<?php

declare(strict_types=1);

namespace App\Models;

use App\Collections\DamageReportCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Damage assessment — polymorphic to Vehicle or WorkOrder.
 *
 * Uses @phpstan-return (not @psalm-return or @return) to test the Larastan migration path.
 * Custom collection via static $collectionClass property — the third detection pattern.
 */
class DamageReport extends Model
{
    /** @var class-string<DamageReportCollection<array-key, static>> */
    protected static string $collectionClass = DamageReportCollection::class;

    /**
     * @phpstan-return MorphTo<Vehicle|WorkOrder, $this>
     */
    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }
}
