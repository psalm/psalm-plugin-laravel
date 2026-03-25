--FILE--
<?php declare(strict_types=1);

$_false = head([]);
/** @psalm-check-type-exact $_false = false */

function data_fill_supports_array(array $input): array
{
    return data_fill($input, 'key', 'value');
}

function data_fill_supports_object(object $input): object
{
    return data_fill($input, 'property', 'value');
}

function data_set_supports_array(array $input): array
{
    return data_set($input, 'key', 'value');
}

function data_set_supports_object(object $input): object
{
    return data_set($input, 'property', 'value');
}

/** @return false */
function empty_head()
{
    return head([]);
}

/** @return false */
function empty_last()
{
    return last([]);
}

function non_empty_head(): int
{
    return last([1, 2, 3]);
}

function non_empty_last(): int
{
    return last([1, 2, 3]);
}

/** When $key is null, data_get returns $target with its original type preserved */
function data_get_with_null_key_returns_target(array $input): array
{
    return data_get($input, null);
}

/** When $key is null, data_get preserves the exact object type */
function data_get_with_null_key_returns_target_object(\stdClass $input): \stdClass
{
    return data_get($input, null);
}

/** When $key is a string, data_get returns mixed (dot-notation traversal can't be typed statically) */
function data_get_with_string_key_returns_mixed(): mixed
{
    return data_get(['foo' => 'bar'], 'foo');
}

function if_first_value_arg_is_closure_then_closure_result_returned(): int
{
    return value(
      \Closure::fromCallable(fn() => 42),
      'some', 'args', 'to', 'pass', 'to', 'closure'
    );
}

function if_first_value_arg_is_not_closure_then_mixed_expected(): mixed
{
    return value(
      42,
      'some', 'args', 'to', 'pass', 'to', 'closure'
    );
}
?>
--EXPECT--
