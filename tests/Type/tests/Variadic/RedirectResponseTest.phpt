--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Http\RedirectResponse;

/**
 * RedirectResponse::onlyInput() / exceptInput() have no formal parameters in
 * Laravel's source — all keys are read from func_get_args(). Without
 * @psalm-variadic, every call would be flagged TooManyArguments.
 */
function redirect_only_input_variadic(RedirectResponse $r): void
{
    $_none = $r->onlyInput();
    /** @psalm-check-type-exact $_none = RedirectResponse&static */

    $_single = $r->onlyInput('email');
    /** @psalm-check-type-exact $_single = RedirectResponse&static */

    $_variadic = $r->onlyInput('email', 'password', 'remember');
    /** @psalm-check-type-exact $_variadic = RedirectResponse&static */
}

function redirect_except_input_variadic(RedirectResponse $r): void
{
    $_none = $r->exceptInput();
    /** @psalm-check-type-exact $_none = RedirectResponse&static */

    $_single = $r->exceptInput('password');
    /** @psalm-check-type-exact $_single = RedirectResponse&static */

    $_variadic = $r->exceptInput('password', '_token', '_method');
    /** @psalm-check-type-exact $_variadic = RedirectResponse&static */
}
?>
--EXPECTF--
