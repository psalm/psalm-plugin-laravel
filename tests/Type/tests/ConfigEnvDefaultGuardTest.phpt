--FILE--
<?php declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

/**
 * A variable set to "null" reads as null (Env::getOption()), so a guard on an env-backed value with a
 * non-null default is not redundant, while unguarded string use stays clean (see the EnvHandler docblock).
 * Keys come from the Testbench skeleton config: 'url' => env('APP_URL', 'http://localhost'),
 * 'timezone' => 'UTC'.
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/2172
 */
function issue_snippet(Request $request): string
{
    $appUrl = config('app.url');

    return is_string($appUrl) && $appUrl !== '' ? mb_rtrim($appUrl, '/') : mb_rtrim($request->root(), '/');
}

function null_guards(): string
{
    $url = config('app.url');
    if ($url === null) {
        return 'x';
    }

    return (string) config('app.url') . (config('app.url') ?? 'x') . $url;
}

function guard_on_every_entry_point(Repository $repository): int
{
    $helperGet = config()->get('app.url');
    $facade = Config::get('app.url');
    $repo = $repository->get('app.url');
    $env = env('APP_URL', 'http://localhost');

    return (is_string($helperGet) ? 1 : 0) + (is_string($facade) ? 1 : 0) + (is_string($repo) ? 1 : 0) + (is_string($env) ? 1 : 0);
}

function takes_string(string $value): string
{
    return $value;
}

function unguarded_use(Repository $repository): int
{
    $url = config('app.url');
    $len = strlen($url) + strlen(config()->get('app.url')) + strlen(Config::get('app.url')) + strlen($repository->get('app.url'));

    return $len + strlen('scheme: ' . $url) + strlen(takes_string(env('APP_URL', 'http://localhost')));
}

function unguarded_return(): string
{
    return config('app.url');
}

function unguarded_use_of_an_env_backed_default_for_an_absent_key(Repository $repository): int
{
    return strlen(config('services.missing.url', config('app.url')))
        + strlen(config('missing.key', env('MISSING_KEY', 'fallback')))
        + strlen(config()->get('missing.key', env('MISSING_KEY', 'fallback')))
        + strlen($repository->get('missing.key', env('MISSING_KEY', 'fallback')))
        + strlen(config('missing.key', static fn () => env('MISSING_KEY', 'fallback')));
}

function default_without_env_default_stays_reported(): int
{
    return strlen(config('missing.key', env('MISSING_KEY')));
}

function config_default_that_may_be_a_genuine_null_stays_reported(bool $useFallback, Repository $repository): int
{
    $fallback = $useFallback ? env('FALLBACK_URL', 'http://localhost') : null;

    $length = strlen(config('missing.key', $fallback));
    $length += strlen(config()->get('missing.key', $fallback));
    $length += strlen($repository->get('missing.key', $fallback));

    return $length + strlen(config('missing.key', static fn () => $useFallback ? env('FALLBACK_URL', 'http://localhost') : null));
}

function unguarded_use_of_env_with_an_env_backed_default(): int
{
    return strlen(env('APP_URL', env('FALLBACK_URL', 'http://localhost'))) + strlen(env('APP_URL', config('app.url')));
}

function env_default_that_may_be_a_genuine_null_stays_reported(bool $useFallback): int
{
    $default = $useFallback ? env('FALLBACK_URL', 'http://localhost') : null;

    return strlen(env('APP_URL', $default));
}

function env_default_from_a_nullsafe_call_stays_reported(?Repository $repository): int
{
    return strlen(env('APP_URL', $repository?->get('app.url')));
}

function guard_on_a_value_without_env_is_still_redundant(): string
{
    $timezone = config('app.timezone');

    return is_string($timezone) ? $timezone : 'UTC';
}
?>
--EXPECTF--
PossiblyNullArgument on line %d: Argument 1 of strlen cannot be null, possibly null value provided
PossiblyNullArgument on line %d: Argument 1 of strlen cannot be null, possibly null value provided
PossiblyNullArgument on line %d: Argument 1 of strlen cannot be null, possibly null value provided
PossiblyNullArgument on line %d: Argument 1 of strlen cannot be null, possibly null value provided
PossiblyNullArgument on line %d: Argument 1 of strlen cannot be null, possibly null value provided
PossiblyNullArgument on line %d: Argument 1 of strlen cannot be null, possibly null value provided
PossiblyNullArgument on line %d: Argument 1 of strlen cannot be null, possibly null value provided
RedundantCondition on line %d: Type string for $timezone is always string
TypeDoesNotContainType on line %d: Type string for $timezone is always !string
