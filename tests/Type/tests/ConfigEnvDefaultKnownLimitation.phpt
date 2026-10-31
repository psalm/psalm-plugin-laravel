--FILE--
<?php declare(strict_types=1);

/**
 * KNOWN LIMITATION: env() reads a set variable as `string` (see the EnvHandler docblock), though
 * Env::get() maps "true", "false" and "null" to bool and null. An env-backed config value with a
 * non-null default is therefore `string`, and an is_string() guard on it looks redundant.
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/707
 */
function is_string_guard_on_env_with_default(): string
{
    // 'url' => env('APP_URL', 'http://localhost')
    $url = config('app.url');

    return is_string($url) ? $url : 'http://localhost';
}
?>
--EXPECTF--
RedundantCondition on line %d: Type string for $url is always string
TypeDoesNotContainType on line %d: Type string for $url is always !string
