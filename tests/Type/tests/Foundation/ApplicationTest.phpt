--FILE--
<?php declare(strict_types=1);

use \Illuminate\Foundation\Application;

function returns_bool_for_string_arg(Application $application, string $env): bool
{
    return $application->environment($env);
}

/** @param non-empty-list<string> $envs */
function returns_bool_for_array_arg(Application $application, array $envs): bool
{
    return $application->environment($envs);
}

function returns_string(Application $application): string
{
    return $application->environment();
}
?>
--EXPECTF--
