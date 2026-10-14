--FILE--
<?php declare(strict_types=1);

use Illuminate\View\InvokableComponentVariable;

/**
 * `T` is the wrapped closure's return type: what `Component::createVariableFromMethod()` hands a
 * view for a no-parameter public method.
 */
function invokable_component_variable_infers_from_its_closure(): void
{
    $variable = new InvokableComponentVariable(static fn(): int => 1);
    /** @psalm-check-type-exact $variable = InvokableComponentVariable<1> */

    $_value = $variable();
    /** @psalm-check-type-exact $_value = 1 */

    $_string = (string) $variable;
    /** @psalm-check-type-exact $_string = string */
}

/** Laravel's constructor takes any `\Closure`: neither shape below is new trouble. */
function invokable_component_variable_accepts_any_closure(\Closure $untyped): void
{
    $_fromUntyped = new InvokableComponentVariable($untyped);
    /** @psalm-check-type-exact $_fromUntyped = InvokableComponentVariable<mixed> */

    $_withParameter = new InvokableComponentVariable(static fn(int $x): int => $x);
    /** @psalm-check-type-exact $_withParameter = InvokableComponentVariable<int> */
}

/** @param InvokableComponentVariable<list<string>> $variable */
function invokable_component_variable_declared_with_its_type(InvokableComponentVariable $variable): void
{
    $_value = $variable();
    /** @psalm-check-type-exact $_value = list<string> */
}

/** The bare class name stays valid: `T` is left open, so the call is mixed, as before. */
function invokable_component_variable_bare(InvokableComponentVariable $variable): void
{
    $_value = $variable();
    /** @psalm-check-type-exact $_value = mixed */
}

/** @param InvokableComponentVariable<string> $_variable */
function takes_invokable_component_variable_of_string(InvokableComponentVariable $_variable): void {}

function invokable_component_variable_is_covariant(): void
{
    /** @var InvokableComponentVariable<non-empty-string> $variable */
    $variable = new InvokableComponentVariable(static fn(): string => 'a');
    takes_invokable_component_variable_of_string($variable);
    takes_invokable_component_variable_of_string(new InvokableComponentVariable(static fn(): int => 1));
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of takes_invokable_component_variable_of_string expects Illuminate\View\InvokableComponentVariable<string>, but Illuminate\View\InvokableComponentVariable<1> provided
