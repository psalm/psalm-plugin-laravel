--FILE--
<?php declare(strict_types=1);

function test_auth_call_without_args_should_return_Factory(): \Illuminate\Contracts\Auth\Factory
{
    return auth();
}

function test_auth_call_with_null_as_arg_should_return_Factory(): \Illuminate\Contracts\Auth\Factory
{
    return auth(null);
}

function test_auth_call_with_string_arg_should_return_Guard(): \Illuminate\Contracts\Auth\Guard|\Illuminate\Contracts\Auth\StatefulGuard
{
    return auth('user');
}

function test_auth_check_call(): bool
{
    return auth()->check();
}
?>
--EXPECTF--
