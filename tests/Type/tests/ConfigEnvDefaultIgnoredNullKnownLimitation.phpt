--FILE--
<?php declare(strict_types=1);

/**
 * KNOWN LIMITATION: the null a set variable reads on an env-backed value with a non-null default is
 * not reported (ignore_nullable_issues, see the EnvHandler docblock). Psalm ORs that flag when it
 * merges unions, so a genuine null joined with such a value is no longer reported downstream: a
 * ternary in user code, or the values of a config array that mixes defaulted and default-less env
 * reads. Template inference drops the flag, so a typed Collection callback rejects the value and a
 * collection() iteration reports it as possibly null. A truthiness check sees the null like on any
 * nullable string.
 * 'url' => env('APP_URL', 'http://localhost'), 'maintenance' => ['driver' => env('APP_MAINTENANCE_DRIVER', 'file'), ...],
 * cache 'database' store => ['connection' => env('DB_CACHE_CONNECTION'), 'table' => env('DB_CACHE_TABLE', 'cache'), ...]
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/2172
 */
function genuine_null_merged_with_env_value(bool $useUrl): int
{
    $url = $useUrl ? config('app.url') : null;

    return strlen($url);
}

function genuine_null_in_an_iterated_config_array(): string
{
    $out = '';
    foreach (config('cache.stores.database') as $value) {
        $out .= $value;
    }

    return $out;
}

/** @return \Illuminate\Support\Collection<int, string> */
function typed_collection_callback(): \Illuminate\Support\Collection
{
    return collect([config('app.url')])->map(static fn (string $url): string => $url);
}

function config_collection_iteration(): int
{
    $length = 0;
    foreach (config()->collection('app.maintenance') as $value) {
        $length += strlen($value);
    }

    return $length;
}

function truthiness_check(): string
{
    return config('app.url') ?: 'http://localhost';
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Collection::map expects callable[impure](null|string, 0):string, but Closure[pure](string):string provided
PossiblyNullArgument on line %d: Argument 1 of strlen cannot be null, possibly null value provided
RiskyTruthyFalsyComparison on line %d: Operand of type null|string contains type string, which can be falsy and truthy. This can cause possibly unexpected behavior. Use strict comparison instead.
