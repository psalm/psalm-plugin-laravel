--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Tests for scope method resolution on Eloquent Relation chains.
 *
 * Laravel's Eloquent Builder forwards calls to the related model's scope methods
 * via __call. The MethodForwardingHandler now detects these scopes on the related
 * model and preserves the Relation's generic type for fluent chaining.
 *
 * Generic params propagate through the user's relation methods automatically via
 * ModelRelationReturnTypeHandler — no @var coercion required.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/646
 */

// Modern #[Scope] attribute (no extra args): Vehicle::#[Scope] electric → electric()
// Customer::vehicles() returns HasMany<Vehicle, Customer>
function test_attribute_scope_on_hasmany(): void {
    $r = (new Customer())->vehicles();
    $_ = $r->electric();
    /** @psalm-check-type-exact $_ = HasMany<\App\Models\Vehicle, Customer> */
}

// Legacy scope with args: Vehicle::scopeByMake(Builder $query, string $make) → byMake('Toyota')
function test_legacy_scope_with_args_on_hasmany(): void {
    $r = (new Customer())->vehicles();
    $_ = $r->byMake('Toyota');
    /** @psalm-check-type-exact $_ = HasMany<\App\Models\Vehicle, Customer> */
}

// Scope + terminal: scope preserves HasMany, first() returns the model type
function test_scope_then_terminal_on_hasmany(): void {
    $r = (new Customer())->vehicles();
    $_ = $r->electric()->first();
    /** @psalm-check-type-exact $_ = \App\Models\Vehicle|null */
}

// Scope chaining: two scopes in a row preserve the Relation generic type
function test_scope_chain_on_hasmany(): void {
    $r = (new Customer())->vehicles();
    $_ = $r->electric()->byMake('Tesla');
    /** @psalm-check-type-exact $_ = HasMany<\App\Models\Vehicle, Customer> */
}

// HasOne: Customer::primaryVehicle() returns HasOne<Vehicle, Customer>
// Vehicle::#[Scope] electric → electric()
function test_attribute_scope_on_hasone(): void {
    $r = (new Customer())->primaryVehicle();
    $_ = $r->electric();
    /** @psalm-check-type-exact $_ = HasOne<\App\Models\Vehicle, Customer> */
}

// BelongsTo: WorkOrder::vehicle() returns BelongsTo<Vehicle, WorkOrder>
// Vehicle::scopeByMake → byMake()
function test_legacy_scope_on_belongsto(): void {
    $r = (new WorkOrder())->vehicle();
    $_ = $r->byMake('Toyota');
    /** @psalm-check-type-exact $_ = BelongsTo<\App\Models\Vehicle, WorkOrder> */
}

// HasManyThrough: legacy scope WorkOrder::scopeUrgent() → urgent()
// Customer::workOrders() returns HasManyThrough<WorkOrder, Vehicle, Customer>
function test_legacy_scope_on_hasmanythrough(): void {
    $r = (new Customer())->workOrders();
    $_ = $r->urgent();
    /** @psalm-check-type-exact $_ = HasManyThrough<\App\Models\WorkOrder, \App\Models\Vehicle, Customer> */
}

// HasManyThrough + modern #[Scope] attribute: WorkOrder::completed()
function test_attribute_scope_on_hasmanythrough(): void {
    $r = (new Customer())->workOrders();
    $_ = $r->completed();
    /** @psalm-check-type-exact $_ = HasManyThrough<\App\Models\WorkOrder, \App\Models\Vehicle, Customer> */
}

// Scope + Builder method chain: scope then where() preserves the Relation generic type
function test_scope_then_builder_method_chain(): void {
    $r = (new Customer())->vehicles();
    $_ = $r->electric()->where('year', '>=', 2020);
    /** @psalm-check-type-exact $_ = HasMany<\App\Models\Vehicle, Customer> */
}

// Negative: a method that is NOT a scope on the related model falls through to mixed.
// The handler does not match it, Relation::__call returns mixed.
function test_non_scope_method_falls_through(): void {
    $r = (new Customer())->vehicles();
    $_ = $r->completelyFakeNotAScope();
    /** @psalm-check-type-exact $_ = mixed */
}
?>
--EXPECTF--
