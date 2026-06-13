--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Regression test for https://github.com/psalm/psalm-plugin-laravel/issues/913 (now fixed).
 *
 * Chaining a Builder/Relation method directly off $this->belongsTo(...) inside a relation
 * method used to collapse the receiver to `mixed` and raise MixedMethodCall.
 *
 * Root cause: stubs/common/Database/Eloquent/Concerns/HasRelationships.phpstub declared
 * belongsTo(): BelongsTo<TRelatedModel, $this>, and Psalm 7 does not late-static-substitute
 * the `$this` template argument when the returned relation is chained, so the intermediate
 * type degraded to mixed.
 *
 * The fix has two halves (see the stub-authoring rules in CLAUDE.md and Relation.phpstub):
 *  1. belongsTo() returns BelongsTo<TRelatedModel, static> (static IS substituted on chaining).
 *  2. TDeclaringModel is @template-covariant across the relation stubs, so the inferred
 *     BelongsTo<Customer, Order&static> on a non-final model satisfies the call-site
 *     @return BelongsTo<Customer, self> (covariance: Order&static <: Order). Without (2) this
 *     regresses to InvalidReturnStatement. Order below is deliberately NON-final so the
 *     covariance requirement is actually exercised (a final model collapses static to self).
 */
class Customer extends Model
{
}

class Order extends Model
{
    /**
     * The exact shape reported in #913. Must type-check with no MixedMethodCall and no
     * InvalidReturnStatement.
     *
     * @return BelongsTo<Customer, self>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withoutGlobalScopes();
    }
}

/**
 * Callers of the declared method see the clean declared return type (self resolves to Order,
 * not Order&static, because self is not late-static-bound).
 */
function call_913_via_method(Order $order): void
{
    $_relation = $order->customer();
    /** @psalm-check-type-exact $_relation = BelongsTo<Customer, Order> */
}

/**
 * The raw stub chain (what the method above wraps). The declaring-model arg carries the
 * late-static intersection Order&static because Order is non-final and belongsTo() returns
 * `static`. This is the precise type; the #913 collapse to `mixed` is gone.
 */
function call_913_raw_chain(Order $order): void
{
    $_relation = $order->belongsTo(Customer::class)->withoutGlobalScopes();
    /** @psalm-check-type-exact $_relation = BelongsTo<Customer, Order&static> */
}

?>
--EXPECTF--
