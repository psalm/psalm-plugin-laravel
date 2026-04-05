<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Test model for non-generic relationship accessor resolution (#497).
 *
 * All relationship methods deliberately omit generic type parameters to test
 * that the plugin resolves property types from the method body AST.
 */
final class Shop extends Model
{
    protected $table = 'shops';

    // --- Single relations (should resolve to ?RelatedModel) ---

    /** BelongsTo without generics */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** HasOne without generics */
    public function latestInvoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    /** MorphOne without generics */
    public function latestReport(): MorphOne
    {
        return $this->morphOne(DamageReport::class, 'reportable');
    }

    /** MorphTo without generics — related model is polymorphic, not statically determinable */
    public function shopable(): MorphTo
    {
        return $this->morphTo();
    }

    // --- Collection relations (should resolve to Collection<int, RelatedModel>) ---

    /** HasMany without generics — WorkOrder has WorkOrderCollection via #[CollectedBy] */
    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    /** BelongsToMany without generics — Part has PartCollection via newCollection() */
    public function parts(): BelongsToMany
    {
        return $this->belongsToMany(Part::class);
    }

    /** MorphMany without generics — Supplier has NO custom collection */
    public function suppliers(): MorphMany
    {
        return $this->morphMany(Supplier::class, 'suppliable');
    }

    /** HasManyThrough without generics */
    public function mechanics(): HasManyThrough
    {
        return $this->hasManyThrough(Mechanic::class, Vehicle::class);
    }

    /** MorphToMany without generics — WorkOrder has WorkOrderCollection */
    public function allWorkOrders(): MorphToMany
    {
        return $this->morphToMany(WorkOrder::class, 'orderable');
    }

    /** HasOneThrough without generics */
    public function vehicleOwner(): HasOneThrough
    {
        return $this->hasOneThrough(Customer::class, Vehicle::class);
    }

    /** morphedByMany — inverse of morphToMany, same Relation class */
    public function morphedWorkOrders(): MorphToMany
    {
        return $this->morphedByMany(WorkOrder::class, 'orderable');
    }

    // --- Edge cases ---

    /** No return type at all — plugin must parse the body to find both relation type and model */
    public function supplierList()
    {
        return $this->morphMany(Supplier::class, 'suppliable');
    }

    /** Chained method call: belongsTo(...)->withDefault() */
    public function defaultCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withDefault();
    }
}
