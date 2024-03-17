--FILE--
<?php declare(strict_types=1);

function test_cache_call_without_args_should_return_CacheManager(): \Illuminate\Cache\CacheManager
{
    return cache();
}

function test_cache_call_with_string_as_arg_should_return_string(): mixed
{
    return cache('key'); // get value
}

function test_cache_call_with_array_as_arg_should_return_bool(): bool
{
    return cache(['key' => 42]); // set value
}
?>
--EXPECTF--
