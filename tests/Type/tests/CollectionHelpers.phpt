--FILE--
<?php declare(strict_types=1);

$_false = head([]);
/** @tests-check-type-exact $_false = false */

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
