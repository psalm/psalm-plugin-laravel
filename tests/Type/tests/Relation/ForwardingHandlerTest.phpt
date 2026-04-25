--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Mechanic;
use App\Models\Part;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Tests for MethodForwardingHandler: verifies that method calls on Eloquent Relations
 * preserve the Relation's generic type for fluent methods and pass through for terminals.
 */

// Path 1: Builder method via @mixin (where is in Builder's declaring_method_ids)
function test_where_preserves_relation_type(): void {
    /** @var HasOne<Invoice, WorkOrder> $r */
    $r = (new WorkOrder())->invoice();
    $_ = $r->where('active', true);
    /** @psalm-check-type-exact $_ = HasOne<Invoice, WorkOrder> */
}

// Path 2: QueryBuilder-only method via __call
function test_orderBy_preserves_relation_type(): void {
    /** @var HasOne<Invoice, WorkOrder> $r */
    $r = (new WorkOrder())->invoice();
    $_ = $r->orderBy('name');
    /** @psalm-check-type-exact $_ = HasOne<Invoice, WorkOrder> */
}

// Chained call across both paths
function test_chain_preserves_relation_type(): void {
    /** @var HasOne<Invoice, WorkOrder> $r */
    $r = (new WorkOrder())->invoice();
    $_ = $r->where('active', true)->orderBy('name');
    /** @psalm-check-type-exact $_ = HasOne<Invoice, WorkOrder> */
}

// Non-fluent: get() from Relation stub returns custom collection (Invoice has InvoiceCollection)
function test_get_returns_collection(): void {
    /** @var HasOne<Invoice, WorkOrder> $r */
    $r = (new WorkOrder())->invoice();
    $_ = $r->get();
    /** @psalm-check-type-exact $_ = \App\Collections\InvoiceCollection */
}

// Mixin-only method NOT on Relation stubs
function test_mixin_only_preserves_relation_type(): void {
    /** @var HasOne<Invoice, WorkOrder> $r */
    $r = (new WorkOrder())->invoice();
    $_ = $r->withoutGlobalScopes();
    /** @psalm-check-type-exact $_ = HasOne<Invoice, WorkOrder> */
}

// Builder::scopes() returns static|mixed. The fluent detector must match any
// self-like atomic in the union, not fail on the mixed branch.
function test_scopes_preserves_relation_type(): void {
    /** @var HasOne<Invoice, WorkOrder> $r */
    $r = (new WorkOrder())->invoice();
    $_ = $r->scopes('urgent');
    /** @psalm-check-type-exact $_ = HasOne<Invoice, WorkOrder> */
}

// Different Relation subclass: BelongsToMany (verifies template params work beyond HasOne)
function test_belongsToMany_where_preserves_relation_type(): void {
    /** @var BelongsToMany<Part, WorkOrder, Pivot, 'pivot'> $r */
    $r = (new WorkOrder())->parts();
    $_ = $r->where('active', true);
    /** @psalm-check-type-exact $_ = BelongsToMany<Part, WorkOrder, Pivot, 'pivot'> */
}

function test_belongsToMany_orderBy_preserves_relation_type(): void {
    /** @var BelongsToMany<Part, WorkOrder, Pivot, 'pivot'> $r */
    $r = (new WorkOrder())->parts();
    $_ = $r->orderBy('name');
    /** @psalm-check-type-exact $_ = BelongsToMany<Part, WorkOrder, Pivot, 'pivot'> */
}

// Non-fluent: first() resolved via Relation stub (workaround for Psalm mixin template bug)
function test_first_returns_model_or_null(): void {
    /** @var HasOne<Invoice, WorkOrder> $r */
    $r = (new WorkOrder())->invoice();
    $_ = $r->first();
    /** @psalm-check-type-exact $_ = Invoice|null */
}

// Terminal after chain: where()+orderBy() preserve HasOne, first() resolves via stub
function test_terminal_after_chain(): void {
    /** @var HasOne<Invoice, WorkOrder> $r */
    $r = (new WorkOrder())->invoice();
    $_ = $r->where('active', true)->orderBy('name')->first();
    /** @psalm-check-type-exact $_ = Invoice|null */
}

// firstOrFail() via Relation stub returns TRelatedModel (no |null)
function test_firstOrFail_returns_model(): void {
    /** @var HasOne<Invoice, WorkOrder> $r */
    $r = (new WorkOrder())->invoice();
    $_ = $r->firstOrFail();
    /** @psalm-check-type-exact $_ = Invoice */
}

// Non-fluent mixin method: count() returns int, not HasOne<Invoice, WorkOrder>
function test_count_returns_int_not_relation(): void {
    /** @var HasOne<Invoice, WorkOrder> $r */
    $r = (new WorkOrder())->invoice();
    $_ = $r->count();
    /** @psalm-check-type-exact $_ = int<0, max> */
}

// HasManyThrough: 3 template params (verifies TGenericObject construction with varying arity)
function test_hasManyThrough_where_preserves_relation_type(): void {
    /** @var HasManyThrough<WorkOrder, Vehicle, Customer> $r */
    $r = (new Customer())->workOrders();
    $_ = $r->where('active', true);
    /** @psalm-check-type-exact $_ = HasManyThrough<WorkOrder, Vehicle, Customer> */
}

// Path 2: limit() is QueryBuilder-only with typed param (int $value).
// MethodParamsProvider forwards the param types so Psalm validates arguments.
function test_limit_validates_arguments(): void {
    /** @var HasOne<Invoice, WorkOrder> $r */
    $r = (new WorkOrder())->invoice();
    $_ = $r->limit(10);
    /** @psalm-check-type-exact $_ = HasOne<Invoice, WorkOrder>&static */
}
?>
--EXPECTF--
