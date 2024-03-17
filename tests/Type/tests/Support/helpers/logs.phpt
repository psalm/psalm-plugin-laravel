--FILE--
<?php declare(strict_types=1);

function test_logs_call_without_args(): \Illuminate\Log\LogManager
{
    return logs();
}

function test_logs_call_with_arg(): \Psr\Log\LoggerInterface
{
    return logs('driver-name');
}
?>
--EXPECTF--
