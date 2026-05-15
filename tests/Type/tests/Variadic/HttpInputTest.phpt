--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Http\Request;

/**
 * Request::only()/except() come from Illuminate\Support\Traits\InteractsWithData
 * (re-used through Illuminate\Http\Concerns\InteractsWithInput). Both accept a
 * single array OR variadic string keys via func_get_args().
 *
 * Request::dump() lives on InteractsWithInput and also uses func_get_args().
 */
function request_only_variadic(Request $request): void
{
    $_single = $request->only('email');
    /** @psalm-check-type-exact $_single = array */

    $_variadic = $request->only('email', 'password', 'remember');
    /** @psalm-check-type-exact $_variadic = array */

    $_array = $request->only(['email', 'password']);
    /** @psalm-check-type-exact $_array = array */
}

function request_except_variadic(Request $request): void
{
    $_single = $request->except('password');
    /** @psalm-check-type-exact $_single = array */

    $_variadic = $request->except('password', '_token', '_method');
    /** @psalm-check-type-exact $_variadic = array */

    $_array = $request->except(['password', '_token']);
    /** @psalm-check-type-exact $_array = array */
}

function request_dump_variadic(Request $request): void
{
    $_none = $request->dump();
    $_single = $request->dump('email');
    $_variadic = $request->dump('email', 'password');
    $_array = $request->dump(['email', 'password']);
}
?>
--EXPECTF--
