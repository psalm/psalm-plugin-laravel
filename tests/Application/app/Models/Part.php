<?php

declare(strict_types=1);

namespace App\Models;

use App\Collections\PartCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Spare part (brake pads, oil filter, etc.).
 *
 * Custom collection via newCollection() override — the second detection pattern.
 */
final class Part extends Model
{
    protected $table = 'parts';

    /**
     * @psalm-return BelongsTo<Supplier>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @psalm-return BelongsToMany<WorkOrder>
     */
    public function workOrders(): BelongsToMany
    {
        return $this->belongsToMany(WorkOrder::class);
    }

    /**
     * Get the entity that ordered this part.
     *
     * Uses @return to test the standard PHPDoc annotation path.
     *
     * @return MorphTo<Supplier|WorkOrder, $this>
     */
    public function orderedBy(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  array<array-key, \Illuminate\Database\Eloquent\Model>  $models
     * @return PartCollection<int, static>
     */
    public function newCollection(array $models = []): PartCollection
    {
        return new PartCollection($models);
    }
}
