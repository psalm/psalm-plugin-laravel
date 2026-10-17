<?php

declare(strict_types=1);

namespace PivotShapeFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Order extends Model
{
    /**
     * The shape of the application fixture's `WorkOrder::parts()` (a two-template docblock, default table
     * `item_order`), which the type-test harness cannot give a schema.
     *
     * @psalm-return BelongsToMany<Item, $this>
     */
    public function items(): BelongsToMany
    {
        return $this->belongsToMany(Item::class)->withPivot('quantity', 'unit_price');
    }
}
