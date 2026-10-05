<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasArchivedRevisions;
use App\Models\Concerns\HasRevisions;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Test model for non-generic relationship accessor resolution (#497).
 *
 * All relationship methods deliberately omit generic type parameters to test
 * that the plugin resolves property types from the method body AST.
 */
final class Shop extends Model
{
    use HasRevisions;
    use HasArchivedRevisions {
        HasArchivedRevisions::revisions insteadof HasRevisions;
    }

    protected $table = 'shops';

    /** Default aggregates: proof source for `artists_count` and the `supplier_total` alias. */
    protected $withCount = ['artists', 'suppliers as supplier_total'];

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

    /** snake_case method name — exercises the direct (non-camelCase) path in resolveRelationPrefix() */
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

    // --- Delegated relation bodies without docblock generics (#1613) ---

    public function openWorkOrders(): HasMany
    {
        return $this->workOrders()->where('status', 'open');
    }

    public function seniorMechanics(): HasManyThrough
    {
        return $this->mechanics()->where('years_experience', '>', 10);
    }

    /** Declares a non-relation type: the delegated relation must not leak into the result. */
    public function workOrderTotal(): int
    {
        return $this->workOrders()->count();
    }

    /** `one()` turns the delegated HasMany into a HasOne, so the parsed HasMany must not be used. */
    public function firstWorkOrder(): HasOne
    {
        return $this->workOrders()->one();
    }

    /** A declared supertype does not prove the class survived the chain: runtime is a HasOne. */
    public function onlyWorkOrder(): Relation
    {
        return $this->workOrders()->one();
    }

    /** A union declaration does not pin the class either: runtime is the HasOne alternative. */
    public function workOrderOrFirst(): HasMany|HasOne
    {
        return $this->workOrders()->one();
    }

    /** An earlier conditional return can yield another relation, so the final delegation proves nothing. */
    public function workOrdersOrInvoices(): HasMany
    {
        if ($this->exists) {
            return $this->hasMany(Invoice::class);
        }

        return $this->workOrders();
    }

    /** `when()` may return its callback's relation instead of the receiver. */
    public function conditionalWorkOrders(): HasMany
    {
        return $this->workOrders()->when($this->exists, fn(): HasMany => $this->hasMany(Invoice::class));
    }

    /** getRelated() leaves the relation: the result is the related model's own relation. */
    public function workOrderRevisions(): HasMany
    {
        return $this->workOrders()->getRelated()->revisions();
    }

    /** Delegates to a helper whose relation is built dynamically: not statically resolvable. */
    public function namedWorkOrders(): HasMany
    {
        return $this->relationNamed('workOrders');
    }

    private function relationNamed(string $name): HasMany
    {
        $relation = $this->{$name}();
        \assert($relation instanceof HasMany);

        return $relation;
    }

    /** Delegation cycle (never called at runtime): resolution must terminate and decline. */
    public function cyclicWorkOrders(): HasMany
    {
        return $this->mirroredWorkOrders();
    }

    public function mirroredWorkOrders(): HasMany
    {
        return $this->cyclicWorkOrders()->latest();
    }

    // --- Bodies with several returns (#1613): every return must resolve to the same relation ---

    /** The helper's early return yields Invoice for this argument, so the delegation proves nothing. */
    public function invoicesViaHelper(): HasMany
    {
        return $this->relationFor('invoices')->latest();
    }

    private function relationFor(string $kind): HasMany
    {
        if ($kind === 'invoices') {
            return $this->hasMany(Invoice::class);
        }

        return $this->hasMany(WorkOrder::class);
    }

    public function invoicesOrWorkOrders(): HasMany
    {
        if ($this->exists) {
            return $this->hasMany(Invoice::class);
        }

        return $this->hasMany(WorkOrder::class);
    }

    public function sortedWorkOrders(): HasMany
    {
        if ($this->exists) {
            return $this->hasMany(WorkOrder::class)->latest();
        }

        return $this->hasMany(WorkOrder::class);
    }

    // --- Direct factory chains that change the relation class (#1647) ---

    public function lastWorkOrder(): HasOne
    {
        return $this->hasMany(WorkOrder::class)->one();
    }

    public function lastSupplier(): MorphOne
    {
        return $this->morphMany(Supplier::class, 'suppliable')->one();
    }

    public function lastMechanic(): HasOneThrough
    {
        return $this->hasManyThrough(Mechanic::class, Vehicle::class)->one();
    }

    public function latestOfManyWorkOrder(): HasOne
    {
        return $this->hasMany(WorkOrder::class)->one()->latestOfMany();
    }

    /** getQuery() leaves the relation: the result is the Eloquent builder, not a HasMany. */
    public function workOrderQuery(): EloquentBuilder
    {
        return $this->hasMany(WorkOrder::class)->getQuery();
    }

    /** Undeclared on purpose: only the chain walk can tell that getQuery() leaves the relation. */
    public function untypedWorkOrderQuery()
    {
        return $this->hasMany(WorkOrder::class)->getQuery();
    }

    /**
     * Deliberately wrong declaration (the body is a HasMany): the parsed class must not override
     * the declared one.
     *
     * @psalm-suppress InvalidReturnStatement
     */
    public function mismatchedWorkOrder(): HasOne
    {
        return $this->hasMany(WorkOrder::class)->where('status', 'open');
    }

    /** A query-builder-only method: Eloquent\Builder::__call() discards its result, so the relation survives. */
    public function beforeCallbackWorkOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class)->applyBeforeQueryCallbacks();
    }

    /** A passthru method returns the base query's result (a bool), not the relation. */
    public function untypedWorkOrdersExist()
    {
        return $this->hasMany(WorkOrder::class)->exists();
    }

    /** clone() returns a new Eloquent builder, which Relation::forwardDecoratedCallTo() does not map back. */
    public function untypedClonedLastWorkOrder()
    {
        return $this->hasMany(WorkOrder::class)->one()->clone();
    }

    /** applyScopes() is declared `static` but returns a clone when global scopes exist. */
    public function untypedScopedWorkOrders()
    {
        return $this->hasMany(WorkOrder::class)->applyScopes();
    }

    /** A dynamic method name could be `one`, so the chain cannot be followed. */
    public function untypedDynamicChainWorkOrders()
    {
        return $this->hasMany(WorkOrder::class)->{'one'}();
    }

    /**
     * Deliberately wrong union (the body is a HasOne): no alternative admits the parsed class.
     *
     * @psalm-suppress InvalidReturnStatement
     */
    public function unionMismatchedWorkOrder(): HasMany|EloquentBuilder
    {
        return $this->hasMany(WorkOrder::class)->one();
    }

    public function unionLastWorkOrder(): HasOne|HasMany
    {
        return $this->hasMany(WorkOrder::class)->one();
    }

    public function nullableLastWorkOrder(): ?HasOne
    {
        return $this->hasMany(WorkOrder::class)->one();
    }

    /** A scalar alternative is no room for a relation: the parsed HasOne is not admitted. */
    public function scalarUnionWorkOrder(): HasMany|false
    {
        return $this->hasMany(WorkOrder::class)->one();
    }

    /**
     * A template admits only through its bound: HasOne is not a HasMany.
     *
     * @template TRelation of HasMany
     *
     * @param class-string<TRelation> $class
     * @return TRelation
     */
    public function templatedBoundWorkOrder(string $class): Relation
    {
        return $this->hasMany(WorkOrder::class)->one();
    }

    /** @return HasOne<WorkOrder, self>&\Countable */
    public function intersectionWorkOrder(): HasOne
    {
        return $this->hasMany(WorkOrder::class)->one();
    }

    /** @return Relation */
    public function widerDocblockWorkOrder(): HasMany
    {
        return $this->hasMany(WorkOrder::class)->one();
    }

    // --- `$this` receiver (#1623 shape) ---

    /** The `??` fallback is meaningful (left side unproven); the loadCount() proof makes the right side `int`. */
    public function workOrderCount(): int
    {
        return $this->work_orders_count ?? $this->loadCount('workOrders')->work_orders_count;
    }

    // --- Accessors named like aggregate accessors (#1623): the real attribute wins ---

    /** @return Attribute<string, never> */
    protected function mechanicsCount(): Attribute
    {
        return Attribute::get(static fn(mixed $value): string => 'many');
    }

    public function getVehicleOwnerExistsAttribute(): string
    {
        return 'yes';
    }
}
