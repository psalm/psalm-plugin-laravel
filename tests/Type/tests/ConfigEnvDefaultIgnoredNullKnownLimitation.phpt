--FILE--
<?php declare(strict_types=1);

/**
 * KNOWN LIMITATION: the null a set variable reads on an env-backed value with a non-null default is
 * not reported (ignore_nullable_issues, see the EnvHandler docblock). Psalm ORs that flag when it
 * merges unions, so a genuine null joined with such a value is no longer reported downstream, and
 * template inference drops it, so a typed Collection callback rejects the value. A truthiness check
 * sees the null like on any nullable string.
 * 'url' => env('APP_URL', 'http://localhost')
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/2172
 */
function genuine_null_merged_with_env_value(bool $useUrl): int
{
    $url = $useUrl ? config('app.url') : null;

    return strlen($url);
}

/** @return \Illuminate\Support\Collection<int, string> */
function typed_collection_callback(): \Illuminate\Support\Collection
{
    return collect([config('app.url')])->map(static fn (string $url): string => $url);
}

function truthiness_check(): string
{
    return config('app.url') ?: 'http://localhost';
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Collection::map expects callable[impure](null|string, 0):string, but Closure[pure](string):string provided
RiskyTruthyFalsyComparison on line %d: Operand of type null|string contains type string, which can be falsy and truthy. This can cause possibly unexpected behavior. Use strict comparison instead.
