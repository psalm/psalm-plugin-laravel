--FILE--
<?php declare(strict_types=1);

function rescue_call_without_default(): ?int
{
    return rescue(fn (): int => 0);
}

function rescue_call_with_default_scalar(): int
{
    return rescue(fn (): int => 0, 42);
}

function rescue_call_with_default_callable(): int
{
    return rescue(fn () => throw new \Exception(), fn () => 42);
}
?>
--EXPECTF--
