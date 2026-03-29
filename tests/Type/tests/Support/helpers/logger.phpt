--FILE--
<?php declare(strict_types=1);

function logger_args(): null
{
    return logger('this should return void');
}

function logger_no_args(): \Illuminate\Log\LogManager
{
    return logger();
}
?>
--EXPECTF--
