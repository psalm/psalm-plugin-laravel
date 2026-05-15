--FILE--
<?php declare(strict_types=1);

use Tests\Psalm\LaravelPlugin\Type\Fixtures\MacroFixtureBag;
use Tests\Psalm\LaravelPlugin\Type\Fixtures\MacroFixtureChild;

/**
 * Foundation tests for issue #758 (Strategy B — runtime reflection).
 *
 * Macros are registered in tests/Type/macro-fixtures.php on `MacroFixtureBag`.
 * The fixture is loaded via the `autoloader` attribute in tests/Type/psalm.xml
 * so the registrations are visible by the time the plugin's
 * `AfterCodebasePopulated` handler reads `Macroable::$macros`.
 *
 * Registering on a fixture class (rather than on `Illuminate\Support\Stringable`
 * or another framework class) sidesteps a Psalm `autoloader`-attribute quirk
 * where force-loading a framework class via the autoloader alters Psalm's
 * argument-count diagnostics for unrelated methods on that class.
 */

function test_instance_macro_return_type(): string
{
    $_ = (new MacroFixtureBag())->shoutTest();
    /** @psalm-check-type-exact $_ = string */
    return $_;
}

function test_instance_macro_param_signature(): int
{
    return (new MacroFixtureBag())->countCharsTest('needle');
}

function test_static_macro_return_type(): string
{
    // Macroable defines __callStatic too; the handler injects each macro into
    // both pseudo_methods AND pseudo_static_methods so both call shapes work.
    $_ = MacroFixtureBag::shoutTest();
    /** @psalm-check-type-exact $_ = string */
    return $_;
}

function test_subclass_inherits_parent_macros_instance_call(): string
{
    // MacroFixtureChild extends MacroFixtureBag. Psalm's instance-call dispatch
    // does NOT walk parent_classes for pseudo-methods, so MacroHandler must
    // explicitly propagate the pseudo-methods to descendants.
    $_ = (new MacroFixtureChild())->shoutTest();
    /** @psalm-check-type-exact $_ = string */
    return $_;
}

function test_subclass_inherits_parent_macros_static_call(): string
{
    $_ = MacroFixtureChild::shoutTest();
    /** @psalm-check-type-exact $_ = string */
    return $_;
}

function test_macro_param_signature_rejects_wrong_argument_type(): int
{
    // Invalid arg type surfaces the macro's params provider — the diagnostic
    // also confirms `cased_name` preserves the original casing (`countCharsTest`,
    // not `countcharstest`).
    return (new MacroFixtureBag())->countCharsTest(42);
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of countCharsTest expects string, but 42 provided
