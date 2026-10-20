--FILE--
<?php declare(strict_types=1);

/*
 * KNOWN LIMITATION: Psalm 7.0.0-rc6 resolves the conditional return types of blank(), filled(),
 * throw_if() and transform() differently from rc5 and earlier. The declared return types below are
 * what Laravel does at runtime; the EXPECTF section pins what the helper stubs currently produce.
 *
 *  - An empty-array candidate is treated as possibly matching `non-empty-array`, so the first arm of
 *    blank()/filled() matches `[]` as well and the result widens to `bool`.
 *  - A conditional on `empty` (throw_if()) matches no input, so every call resolves to `never` and
 *    the code after it is reported as UnevaluatedCode.
 *  - transform([], ...) evaluates the same widened conditional and returns the callback and the
 *    default type together instead of the default alone.
 *
 * Flipped by the helper stub rewrite in stubs/common/Support/helpers.phpstub.
 */

/** @return true */
function empty_array_is_blank(): bool
{
    return blank([]);
}

/** @return false */
function empty_array_is_not_filled(): bool
{
    return filled([]);
}

/** @return false */
function throw_if_with_bool_arg(bool $var): bool
{
    throw_if($var);
    return $var;
}

/** @return ''|'0' **/
function throw_if_with_string_arg(string $var): string
{
    throw_if($var);
    return $var;
}

/** @return list<never> **/
function throw_if_with_array_arg(array $var): array
{
    throw_if($var);
    return $var;
}

/** @return 0 **/
function throw_if_with_int_arg(int $var): int
{
    throw_if($var);
    return $var;
}

/** @return 0.0 **/
function throw_if_with_float_arg(float $var): float
{
    throw_if($var);
    return $var;
}

function it_uses_default_return_type_if_value_is_blank_and_default_is_callable(): int {
    return transform([], fn () => 'any', fn () => 42);
}
?>
--EXPECTF--
InvalidReturnType on line %d: The declared return type 'true' for empty_array_is_blank is incorrect, got 'bool'
InvalidReturnStatement on line %d: The inferred type 'bool' does not match the declared return type 'true' for empty_array_is_blank
InvalidReturnType on line %d: The declared return type 'false' for empty_array_is_not_filled is incorrect, got 'bool'
InvalidReturnStatement on line %d: The inferred type 'bool' does not match the declared return type 'false' for empty_array_is_not_filled
InvalidReturnType on line %d: The declared return type 'false' for throw_if_with_bool_arg is incorrect, got 'never'
UnevaluatedCode on line %d: Expressions after return/throw/continue
InvalidReturnType on line %d: The declared return type '''|'0'' for throw_if_with_string_arg is incorrect, got 'never'
UnevaluatedCode on line %d: Expressions after return/throw/continue
UnevaluatedCode on line %d: Expressions after return/throw/continue
InvalidReturnType on line %d: The declared return type '0' for throw_if_with_int_arg is incorrect, got 'never'
UnevaluatedCode on line %d: Expressions after return/throw/continue
InvalidReturnType on line %d: The declared return type 'float(0)' for throw_if_with_float_arg is incorrect, got 'never'
UnevaluatedCode on line %d: Expressions after return/throw/continue
InvalidReturnType on line %d: The declared return type 'int' for it_uses_default_return_type_if_value_is_blank_and_default_is_callable is incorrect, got ''any'|42'
InvalidReturnStatement on line %d: The inferred type ''any'|42' does not match the declared return type 'int' for it_uses_default_return_type_if_value_is_blank_and_default_is_callable
