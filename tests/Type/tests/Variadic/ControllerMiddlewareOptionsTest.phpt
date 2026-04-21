--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Routing\ControllerMiddlewareOptions;

/**
 * ControllerMiddlewareOptions::only() / except() mirror the resource registration
 * pattern — variadic method names via func_get_args().
 */
function middleware_only_variadic(ControllerMiddlewareOptions $opts): void
{
    $_variadic = $opts->only('show', 'edit');
    /** @psalm-check-type-exact $_variadic = ControllerMiddlewareOptions&static */

    $_array = $opts->only(['show', 'edit']);
    /** @psalm-check-type-exact $_array = ControllerMiddlewareOptions&static */

    $_single = $opts->only('show');
    /** @psalm-check-type-exact $_single = ControllerMiddlewareOptions&static */
}

function middleware_except_variadic(ControllerMiddlewareOptions $opts): void
{
    $_variadic = $opts->except('destroy', 'update');
    /** @psalm-check-type-exact $_variadic = ControllerMiddlewareOptions&static */

    $_array = $opts->except(['destroy']);
    /** @psalm-check-type-exact $_array = ControllerMiddlewareOptions&static */

    $_single = $opts->except('destroy');
    /** @psalm-check-type-exact $_single = ControllerMiddlewareOptions&static */
}
?>
--EXPECTF--
