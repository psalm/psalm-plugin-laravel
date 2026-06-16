--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pins scope calls inside when()/unless() closures — the most common real-world conditional
 * query shape, previously untested.
 *
 * Three findings are pinned:
 *
 * 1. The chain stays a builder. Conditionable::when()/unless() are stubbed `@return $this`
 *    (stubs/common/Support/Traits/Conditionable.phpstub) because Psalm 7 collapses Laravel's
 *    templated `@return $this|TWhenReturnType` to mixed. So the closure's own return value is
 *    discarded and the chain keeps the receiver type — here Builder<Customer>&static. The outer
 *    `&static` is introduced by when()'s `@return $this` (Customer::query() alone is plain
 *    Builder<Customer>, per StaticBuilderMethodsTest::test_static_query).
 *
 * 2. LIMITATION — an UNANNOTATED closure parameter is `mixed`. The stub types $callback as a
 *    bare `?callable` with no callable-signature, so Psalm cannot push Builder<Customer> into
 *    $q. Until the stub gains a typed callable, an unannotated $q->scope() is a MixedMethodCall
 *    (and Psalm also flags the untyped closure param/return). This is the behavior a future
 *    typed-callback stub would change.
 *
 * 3. The escape hatch works. A closure that annotates `@param Builder<Customer> $q` resolves the
 *    scope cleanly with no diagnostics — the path real users rely on today.
 */

/** Closure RETURNS the scoped builder; when() discards it and the chain stays the builder. */
function test_scope_inside_when_arrow_closure_keeps_builder(bool $flag): void
{
    $_result = Customer::query()->when($flag, fn ($q) => $q->active());
    /** @psalm-check-type-exact $_result = Builder<Customer>&static */
}

/** Closure RETURNS void; pins that the param is mixed (no typed callable in the stub). */
function test_when_closure_parameter_is_mixed(bool $flag): void
{
    Customer::query()->when($flag, function ($q): void {
        /** @psalm-check-type-exact $q = mixed */
        $q->active();
    });
}

/** unless() behaves identically to when() for chaining. */
function test_scope_inside_unless_arrow_closure_keeps_builder(bool $flag): void
{
    $_result = Customer::query()->unless($flag, fn ($q) => $q->active());
    /** @psalm-check-type-exact $_result = Builder<Customer>&static */
}

/** Escape hatch: annotating the closure param types $q and resolves the scope with no errors. */
function test_when_annotated_closure_resolves_scope(bool $flag): void
{
    Customer::query()->when($flag, /** @param Builder<Customer> $q */ function ($q): void {
        /** @psalm-check-type-exact $q = Builder<Customer> */
        $q->active();
    });
}
?>
--EXPECTF--
MissingClosureReturnType on line %d: Closure does not have a return type, expecting mixed
MissingClosureParamType on line %d: Parameter $q has no provided type
MixedMethodCall on line %d: Cannot determine the type of $q when calling method active
MissingClosureParamType on line %d: Parameter $q has no provided type
MixedMethodCall on line %d: Cannot determine the type of $q when calling method active
MissingClosureReturnType on line %d: Closure does not have a return type, expecting mixed
MissingClosureParamType on line %d: Parameter $q has no provided type
MixedMethodCall on line %d: Cannot determine the type of $q when calling method active
