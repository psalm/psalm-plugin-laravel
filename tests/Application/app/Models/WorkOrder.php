<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\WorkOrderBuilder;
use App\Collections\WorkOrderCollection;
use Illuminate\Database\Eloquent\Attributes\CollectedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single repair visit linking vehicle, mechanic, and parts.
 *
 * Custom query builder via #[UseEloquentBuilder] attribute (Laravel 12+).
 * Custom collection via #[CollectedBy].
 * Uses SoftDeletes to test trait-declared builder methods on custom builders.
 *
 * @see https://laravel-news.com/defining-a-dedicated-query-builder-in-laravel-12-with-php-attributes
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/631
 */
#[CollectedBy(WorkOrderCollection::class)]
#[UseEloquentBuilder(WorkOrderBuilder::class)]
final class WorkOrder extends Model
{
    use SoftDeletes;

    protected $table = 'work_orders';

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function mechanic(): BelongsTo
    {
        return $this->belongsTo(Mechanic::class);
    }

    /**
     * @psalm-return HasOne<Invoice, $this>
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    /**
     * @psalm-return BelongsToMany<Part, $this>
     */
    public function parts(): BelongsToMany
    {
        return $this->belongsToMany(Part::class)->withPivot('quantity', 'unit_price');
    }

    public function damageReports(): MorphMany
    {
        return $this->morphMany(DamageReport::class, 'reportable');
    }

    /**
     * Legacy scope: exercises the scope + custom builder return type path.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUrgent($query)
    {
        return $query->where('priority', 'urgent');
    }

    /**
     * Legacy scope with parameter: exercises the getScopeParams path.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Modern #[Scope] attribute scope.
     *
     * Must be protected — see Customer::verified() for the rationale.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function completed(Builder $query): void
    {
        $query->where('status', 'completed');
    }
}
