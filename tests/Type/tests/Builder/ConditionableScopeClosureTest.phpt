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
 * 2. An UNANNOTATED closure parameter is typed from the call site. The stub types $callback as a
 *    bare `?callable`; ConditionableCallbackParamsHandler supplies
 *    `callable(Builder<Customer>, <truthy $flag>)` per call (#1624), so $q->scope() resolves with
 *    no MixedMethodCall and no MissingClosureParamType/ReturnType.
 *
 * 3. An explicit `@param Builder<Customer> $q` annotation still resolves the scope cleanly.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1624
 */

/** Closure RETURNS the scoped builder; when() discards it and the chain stays the builder. */
function test_scope_inside_when_arrow_closure_keeps_builder(bool $flag): void
{
    $_result = Customer::query()->when($flag, fn ($q) => $q->active());
    /** @psalm-check-type-exact $_result = Builder<Customer>&static */
}

/** Closure RETURNS void; the unannotated param is the receiver builder. */
function test_when_closure_parameter_is_receiver(bool $flag): void
{
    Customer::query()->when($flag, function ($q): void {
        /** @psalm-check-type-exact $q = Builder<Customer> */
        $q->active();
    });
}

/** unless() behaves identically to when() for chaining. */
function test_scope_inside_unless_arrow_closure_keeps_builder(bool $flag): void
{
    $_result = Customer::query()->unless($flag, fn ($q) => $q->active());
    /** @psalm-check-type-exact $_result = Builder<Customer>&static */
}

/** Annotating the closure param keeps working and resolves the scope with no errors. */
function test_when_annotated_closure_resolves_scope(bool $flag): void
{
    Customer::query()->when($flag, /** @param Builder<Customer> $q */ function ($q): void {
        /** @psalm-check-type-exact $q = Builder<Customer> */
        $q->active();
    });
}
?>
--EXPECTF--
