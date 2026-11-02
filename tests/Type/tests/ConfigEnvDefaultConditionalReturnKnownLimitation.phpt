--FILE--
<?php declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;

/**
 * KNOWN LIMITATION: a helper whose return type is conditional on a null argument resolves both arms
 * for an env value with a non-null default, because Psalm's conditional return ignores
 * ignore_nullable_issues on the `null` that value carries (see the EnvDefaultType docblock).
 * Config::string('key') avoids it.
 * 'url' => env('APP_URL', 'http://localhost'), 'name' => env('APP_NAME', 'Laravel')
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/2172
 */
function redirect_to_env_backed_url(): RedirectResponse
{
    return redirect(config('app.url'));
}

function url_of_env_backed_url(): string
{
    return url(config('app.url'));
}

function timezone_from_env(): string
{
    // As in the Laravel skeleton: 'timezone' => env('APP_TIMEZONE', 'UTC')
    return Carbon::now()->tz(env('APP_TIMEZONE', 'UTC'))->format('Y-m-d');
}

function translate_env_backed_name(): string
{
    return __(config('app.name'));
}
?>
--EXPECTF--
InvalidReturnType on line %d: The declared return type 'Illuminate\Http\RedirectResponse' for redirect_to_env_backed_url is incorrect, got 'Illuminate\Http\RedirectResponse|Illuminate\Routing\Redirector'
InvalidReturnStatement on line %d: The inferred type 'Illuminate\Http\RedirectResponse|Illuminate\Routing\Redirector' does not match the declared return type 'Illuminate\Http\RedirectResponse' for redirect_to_env_backed_url
InvalidReturnType on line %d: The declared return type 'string' for url_of_env_backed_url is incorrect, got 'Illuminate\Contracts\Routing\UrlGenerator|string'
InvalidReturnStatement on line %d: The inferred type 'Illuminate\Contracts\Routing\UrlGenerator|string' does not match the declared return type 'string' for url_of_env_backed_url
PossiblyInvalidMethodCall on line %d: Cannot call method on possible string variable 
InvalidNullableReturnType on line %d: The declared return type 'string' for translate_env_backed_name is not nullable, but 'null|string' contains null
NullableReturnStatement on line %d: The declared return type 'string' for translate_env_backed_name is not nullable, but the function returns 'null|string'
