--FILE--
<?php declare(strict_types=1);

function config_with_one_argument(): mixed
{
    return config('app.name');
}

function config_with_first_null_argument_and_second_argument_provided(): mixed
{
    return config('app.non-existent', false);
}

function config_setting_at_runtime(): null
{
    return config(['app.non-existent' => false]);
}
?>
--EXPECTF--
