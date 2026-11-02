--FILE--
<?php declare(strict_types=1);

/**
 * KNOWN LIMITATION: Env::getOption() maps a variable set to "true" or "false" to a bool, but the env
 * model leaves true/false out unless the default contributes them (Psalm has no ignore flag for bool,
 * see the EnvHandler docblock). A bool guard on a string-default value therefore looks impossible.
 * 'url' => env('APP_URL', 'http://localhost')
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/2172
 */
function is_bool_guard(): string
{
    $url = config('app.url');
    if (is_bool($url)) {
        return 'http://localhost';
    }

    return (string) $url;
}

function true_guard(): string
{
    $url = config('app.url');
    if ($url === true) {
        return 'http://localhost';
    }

    return (string) $url;
}

function false_guard(): string
{
    $url = config('app.url');
    if ($url === false) {
        return 'http://localhost';
    }

    return (string) $url;
}
?>
--EXPECTF--
TypeDoesNotContainType on line %d: Type null|string for $url is never bool
TypeDoesNotContainType on line %d: null|string does not contain true
TypeDoesNotContainType on line %d: null|string does not contain false
