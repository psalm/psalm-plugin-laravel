--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/913
 * It documents the existing issue as is, ideally this test should have zero expected Psalm output.
 *
 * Pins the CURRENT (broken) behavior: chaining a Builder/Relation method directly off
 * $this->belongsTo(...) inside a relation method collapses the receiver to `mixed` and
 * raises MixedMethodCall.
 *
 * Root cause: stubs/common/Database/Eloquent/Concerns/HasRelationships.phpstub declares
 * belongsTo(): BelongsTo<TRelatedModel, $this>. Psalm 7 does not substitute the `$this`
 * template argument when the returned relation is chained, so the intermediate type
 * degrades to mixed (see the `$this`-vs-`static` note in CLAUDE.md / stub-authoring rules).
 *
 * The fix is NOT a one-liner: switching `$this` to `static` removes the collapse but then
 * BelongsTo<Customer, Order&static> no longer matches a declared `@return BelongsTo<Customer, self>`
 * (InvalidReturnStatement). A clean fix also needs TDeclaringModel to be covariant across the
 * relation stubs. Both halves were verified against this exact shape.
 *
 * Contrast: tests/Type/tests/Relation/ForwardingHandlerTest.phpt::test_mixin_only_preserves_relation_type
 * shows withoutGlobalScopes() works when the relation is first bound to a concrete local — the
 * App model's own @return annotation applies there; the collapse only happens off the raw stub call.
 */
class Customer extends Model
{
}

class Order extends Model
{
    /**
     * The exact shape reported in #913.
     *
     * @return BelongsTo<Customer, self>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withoutGlobalScopes();
    }
}

/**
 * The same collapse observed at a call site: the relation method above merely wraps this
 * expression. Asserts the type that SHOULD be inferred; the EXPECTF mismatch below records
 * that the plugin produces `mixed` today.
 */
function pin_913_call_site(Order $order): void
{
    $_relation = $order->belongsTo(Customer::class)->withoutGlobalScopes();
    /** @psalm-check-type-exact $_relation = BelongsTo<Customer, Order> */
}

?>
--EXPECTF--
MixedReturnStatement on line %d: Could not infer a return type
MixedMethodCall on line %d: Cannot determine the type of the object on the left hand side of this expression
MixedMethodCall on line %d: Cannot determine the type of the object on the left hand side of this expression
CheckType on line %d: Checked variable $_relation = Illuminate\Database\Eloquent\Relations\BelongsTo<Tests\Psalm\LaravelPlugin\Sandbox\Customer, Tests\Psalm\LaravelPlugin\Sandbox\Order> does not match $_relation = mixed
