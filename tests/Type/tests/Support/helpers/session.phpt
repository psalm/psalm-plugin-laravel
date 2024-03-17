--FILE--
<?php declare(strict_types=1);

function scalar_arg_to_get_value_from_session(): mixed
{
    return session('some-key');
}

function array_arg_to_set_session(): null
{
    return session(['some-key' => 42]);
}

function no_args(): \Illuminate\Session\SessionManager
{
    return session();
}
?>
--EXPECTF--
