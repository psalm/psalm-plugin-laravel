--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\ScopeMacroCollisionModel;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pins the consume-once behavior of BuilderScopeHandler's scope hand-off.
 *
 * A scope call on one model populates the producer->consumer hand-off keyed by the bare method
 * name. If that entry survived, a same-named SoftDeletes macro on a DIFFERENT model analyzed
 * afterwards would be checked against the scope's (zero-arg) signature instead of the macro's.
 *
 * ScopeMacroCollisionModel declares scopeWithTrashed (no SoftDeletes); its call below populates
 * the `withtrashed` hand-off. The consumer unsets it, so the subsequent Customer (SoftDeletes)
 * withTrashed(false) resolves against the macro signature and accepts the bool argument with no
 * false TooManyArguments. The statements run in order within one analysis pass, so the hand-off
 * state genuinely flows from the first call to the second.
 */
function test_scope_handoff_does_not_shadow_softdeletes_macro(): void
{
    // Populates and immediately consumes the `withtrashed` hand-off for ScopeMacroCollisionModel.
    ScopeMacroCollisionModel::query()->withTrashed();

    // SoftDeletes macro on a different model: must use the macro signature (accepts the bool),
    // not the stale scope signature that would reject the argument.
    $_result = Customer::query()->withTrashed(false);
    /** @psalm-check-type-exact $_result = Builder<Customer> */
}
?>
--EXPECTF--
