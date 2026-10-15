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

/**
 * Laravel keys the registered providers by their class name (Application::markAsRegistered()),
 * and Arr::where() in getProviders() preserves those keys, so the result is not a list.
 *
 * @return array<class-string<\Illuminate\Support\ServiceProvider>, \Illuminate\Support\ServiceProvider>
 */
function returns_providers_keyed_by_class(Application $application): array
{
    return $application->getProviders(\Illuminate\Support\ServiceProvider::class);
}
?>
--EXPECTF--
