<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Damage assessment — polymorphic to Vehicle or WorkOrder.
 *
 * Uses @phpstan-return (not @psalm-return or @return) to test the Larastan migration path.
 */
class DamageReport extends Model
{
    /**
     * @phpstan-return MorphTo<Vehicle|WorkOrder, $this>
     */
    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }
}
