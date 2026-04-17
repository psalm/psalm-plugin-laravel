<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\VehicleBuilder;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Car or truck belonging to a customer.
 *
 * Custom query builder via newEloquentBuilder() override (pre-Laravel 12 pattern).
 *
 * @property string $make  Manufacturer (e.g. "Toyota")
 * @property string $model Vehicle model name (e.g. "Camry")
 */
final class Vehicle extends Model
{
    protected $table = 'vehicles';

    /**
     * @psalm-return BelongsTo<Customer>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    /**
     * Latest damage report for this vehicle.
     *
     * @psalm-return MorphOne<DamageReport>
     */
    public function latestReport(): MorphOne
    {
        return $this->morphOne(DamageReport::class, 'reportable');
    }

    /**
     * Most severe damage report (MorphOneOfMany).
     *
     * @psalm-return MorphOne<DamageReport>
     */
    public function mostSevereReport(): MorphOne
    {
        return $this->morphOne(DamageReport::class, 'reportable')->ofMany('severity', 'max');
    }

    /**
     * All damage reports for this vehicle.
     *
     * @psalm-return MorphMany<DamageReport>
     */
    public function damageReports(): MorphMany
    {
        return $this->morphMany(DamageReport::class, 'reportable');
    }

    /**
     * Legacy scope with parameter: exercises the getScopeParams path.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeByMake($query, string $make)
    {
        return $query->where('make', $make);
    }

    /**
     * Modern #[Scope] attribute scope.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    public function electric(Builder $query): void
    {
        $query->where('fuel_type', 'electric');
    }

    public function newEloquentBuilder($query): VehicleBuilder
    {
        return new VehicleBuilder($query);
    }
}
