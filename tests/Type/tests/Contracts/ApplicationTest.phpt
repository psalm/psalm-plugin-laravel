--FILE--
<?php declare(strict_types=1);

function returns_bool_for_string_arg(string $env): bool
{
    return app()->environment($env);
}

/** @param non-empty-list<string> $envs */
function returns_bool_for_array_arg(array $envs): bool
{
    return app()->environment($envs);
}

function returns_string(): string
{
    return app()->environment();
}
?>
--EXPECTF--
