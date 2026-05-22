--FILE--
<?php declare(strict_types=1);

use Tests\Psalm\LaravelPlugin\Type\Fixtures\MacroFixtureBag;
use Tests\Psalm\LaravelPlugin\Type\Fixtures\MacroFixtureChild;

/**
 * Fluent macro narrowing — issue #899 §C signal 1.
 *
 * `fluentTest` is registered in `tests/Type/macro-fixtures.php` as a closure
 * with `: static` return type. Calling it on a subclass instance must narrow
 * the result to the subclass type, not collapse to the declaring class.
 *
 * Mechanism: `MissingMethodCallHandler::handleMagicMethod()` calls
 * `TypeExpander::expandUnion` with the lhs caller as the `$static_class_type`
 * argument; if the pseudo-method's `return_type` preserves the literal `static`
 * token (a `TNamedObject('static')`), the expander rewrites it to the caller.
 * Psalm renders the result as `Class&static` to signal that further chaining
 * keeps the late-bound type alive.
 *
 * Plugin invariant: `MacroRegistry::reflectionTypeToUnion()` MUST NOT flatten
 * `static` to a concrete FQCN for Closure callables. Macroable rebinds the
 * closure via `bindTo($this, static::class)` so `static` resolves to the call
 * site at runtime — the type must follow that binding. The
 * `expandSelfStaticParent()` helper that DOES flatten `static` is gated behind
 * `$reflection instanceof \ReflectionMethod`, which excludes closures by
 * construction; this test locks that contract in against future refactors.
 */

function test_fluent_macro_narrows_on_subclass_instance(): MacroFixtureChild
{
    $_ = (new MacroFixtureChild())->fluentTest();
    /** @psalm-check-type-exact $_ = Tests\Psalm\LaravelPlugin\Type\Fixtures\MacroFixtureChild&static */
    return $_;
}

function test_fluent_macro_returns_declaring_class_on_root_instance(): MacroFixtureBag
{
    $_ = (new MacroFixtureBag())->fluentTest();
    /** @psalm-check-type-exact $_ = Tests\Psalm\LaravelPlugin\Type\Fixtures\MacroFixtureBag&static */
    return $_;
}

function test_fluent_macro_chains_preserve_caller_type(): string
{
    // After fluentTest() narrows to MacroFixtureChild&static, the next call in
    // the chain must still see the macros inherited from the declaring class.
    $_ = (new MacroFixtureChild())->fluentTest()->shoutTest();
    /** @psalm-check-type-exact $_ = string */
    return $_;
}

function test_fluent_macro_chain_preserves_narrowing_across_two_hops(): string
{
    // Two-hop fluent chain — each `__call` dispatch re-runs
    // `TypeExpander::expandUnion`, so the second hop's lhs caller must still
    // be a `TNamedObject('static')`-aware type, not the literal expanded
    // FQCN. A regression where `static` is substituted earlier in the
    // pipeline would let the first hop pass and silently flatten the
    // second, so the terminator must be a macro that requires resolution
    // on the doubly-narrowed type (`shoutTest` is registered on
    // `MacroFixtureBag`).
    $_ = (new MacroFixtureChild())->fluentTest()->fluentTest()->shoutTest();
    /** @psalm-check-type-exact $_ = string */
    return $_;
}

/**
 * Probe class anchored on the Macroable host. Exercises the `$this->macro()`
 * lhs shape — different from `(new Foo())->macro()` because `$this` carries
 * a `TThis` flag through the type-expansion pipeline. A regression that
 * substituted `static` against an `is_static_resolved` receiver too early
 * would only surface here.
 */
final class ProbeFluentMacroUser extends MacroFixtureBag
{
    public function callFluent(): static
    {
        $_ = $this->fluentTest();
        /** @psalm-check-type-exact $_ = ProbeFluentMacroUser&static */
        return $_;
    }
}

function test_fluent_macro_static_call_narrows_on_subclass(): MacroFixtureChild
{
    // `Macroable` defines `__callStatic`, so the pseudo-method also lands in
    // `pseudo_static_methods`. The static-call path uses
    // `AtomicStaticCallAnalyzer`, which threads the receiver class through
    // `TypeExpander` the same way the instance path does.
    $_ = MacroFixtureChild::fluentTest();
    /** @psalm-check-type-exact $_ = Tests\Psalm\LaravelPlugin\Type\Fixtures\MacroFixtureChild&static */
    return $_;
}
?>
--EXPECTF--
