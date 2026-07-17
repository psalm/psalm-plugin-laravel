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
use Illuminate\Database\Eloquent\Relations\Pivot;

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

    /** HasMany whose related model uses a method inherited from an abstract custom builder. */
    public function artists(): HasMany
    {
        return $this->hasMany(Artist::class);
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

    /** snake_case method name — exercises the direct (non-camelCase) path in isRelationPrefix() */
    public function damage_reports(): MorphMany
    {
        return $this->morphMany(DamageReport::class, 'reportable');
    }

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

    // --- Helper-delegation pattern (regression for #882) ---
    //
    // A public relation method whose body delegates to a private helper that
    // builds the relation. Without parser support for following helper calls,
    // RelationMethodParser bails on `$this->workOrdersByStatus(...)` (not a
    // factory name) and the handler returns null, causing Psalm to fall back
    // to the untemplated stub default `HasMany<Model, Model>`.

    /** @psalm-return HasMany<WorkOrder, self> */
    public function activeWorkOrders(): HasMany
    {
        return $this->workOrdersByStatus('active');
    }

    /** @psalm-return HasMany<WorkOrder, self> */
    public function completedWorkOrders(): HasMany
    {
        return $this->workOrdersByStatus('completed');
    }

    private function workOrdersByStatus(string $status): HasMany
    {
        return $this->hasMany(WorkOrder::class)
            ->where('status', $status);
    }

    // --- Wrapper-method-on-this pattern (regression for #884) ---
    //
    // The wrapper body re-enters the chain via `$this->parts()->wherePivot(...)`.
    // The Relation stubs return `$this` from `wherePivot`/`where`, so Psalm
    // attaches `&static` to the outer atomic. If the wrapper's declaration is
    // a concrete-class form like `@psalm-return BelongsToMany<Part, self, Pivot, 'pivot'>`,
    // the outer `&static` must NOT block the assignment.

    /** @psalm-return BelongsToMany<Part, self, Pivot, 'pivot'> */
    public function suggestedParts(): BelongsToMany
    {
        return $this->parts()->wherePivot('priority', 'high');
    }

    /** @psalm-return HasMany<WorkOrder, self> */
    public function recentWorkOrders(): HasMany
    {
        return $this->workOrders()->where('created_at', '>=', '2025-01-01');
    }
}
