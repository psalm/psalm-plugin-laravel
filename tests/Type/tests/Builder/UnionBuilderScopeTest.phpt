--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\DirectScopeModel;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pins union-LHS scope resolution.
 *
 * The return-type / params providers see the whole LHS union, but Psalm dispatches the method
 * per-atomic. When the receiver is a single Builder generic the scope resolves normally; when it
 * is a union of 2+ DISTINCT Builder generics the providers cannot tell which atomic they are
 * answering for, so they decline to honest mixed instead of silently resolving to the FIRST
 * model's scope (which would drop the other arm and check arguments against the wrong model).
 *
 * Customer (legacy scopeActive) and DirectScopeModel (#[Scope] active) both declare an `active`
 * scope and are base-Builder models, so the ternary below yields Builder<Customer>|Builder<
 * DirectScopeModel> — exactly the 2-distinct-generic shape. Because the call resolves to mixed,
 * no scope params are handed off either, so the argument is not checked against either model.
 */

/** Single Builder generic: the scope resolves to Builder<Customer>. */
function test_single_builder_scope_resolves(): void
{
    $b = Customer::query();
    $_result = $b->active();
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}

/** Union of 2 distinct Builder generics, both declaring `active`: declines to mixed. */
function test_union_distinct_builder_generics_decline_to_mixed(bool $cond): void
{
    $b = $cond ? Customer::query() : DirectScopeModel::query();
    $_result = $b->active();
    /** @psalm-check-type-exact $_result = mixed */
}
?>
--EXPECTF--
