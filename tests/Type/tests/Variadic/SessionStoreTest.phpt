--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Session\Store;

/**
 * Session Store::has(), hasAny(), exists(), keep() accept either a single key,
 * an array of keys, or variadic key arguments via func_get_args().
 */
function session_has_variadic(Store $session): void
{
    $_single = $session->has('user_id');
    /** @psalm-check-type-exact $_single = bool */

    $_variadic = $session->has('user_id', 'user_role', 'csrf_token');
    /** @psalm-check-type-exact $_variadic = bool */

    $_array = $session->has(['user_id', 'user_role']);
    /** @psalm-check-type-exact $_array = bool */
}

function session_has_any_variadic(Store $session): void
{
    $_single = $session->hasAny('user_id');
    /** @psalm-check-type-exact $_single = bool */

    $_variadic = $session->hasAny('user_id', 'guest_id');
    /** @psalm-check-type-exact $_variadic = bool */
}

function session_exists_variadic(Store $session): void
{
    $_single = $session->exists('user_id');
    /** @psalm-check-type-exact $_single = bool */

    $_variadic = $session->exists('user_id', 'user_role');
    /** @psalm-check-type-exact $_variadic = bool */
}

function session_keep_variadic(Store $session): void
{
    // keep() returns void. Exercise all accepted call shapes.
    $session->keep();
    $session->keep('success');
    $session->keep('success', 'info', 'warning');
    $session->keep(['success', 'info']);
}
?>
--EXPECTF--
