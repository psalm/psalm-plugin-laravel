--FILE--
<?php declare(strict_types=1);

use Illuminate\Session\Store;

function test_session_store_types(Store $session): void
{
    $_get = $session->get('key');
    /** @psalm-check-type-exact $_get = mixed */

    $_getDefault = $session->get('key', 'default');
    /** @psalm-check-type-exact $_getDefault = mixed */

    $_pull = $session->pull('key');
    /** @psalm-check-type-exact $_pull = mixed */

    $_remove = $session->remove('key');
    /** @psalm-check-type-exact $_remove = mixed */

    $_all = $session->all();
    /** @psalm-check-type-exact $_all = array */

    $_only = $session->only(['key1', 'key2']);
    /** @psalm-check-type-exact $_only = array */

    $_except = $session->except(['key1']);
    /** @psalm-check-type-exact $_except = array */

    // getOldInput() with key → mixed, without key → array
    $_oldInput = $session->getOldInput('email');
    /** @psalm-check-type-exact $_oldInput = mixed */

    $_allOldInput = $session->getOldInput();
    /** @psalm-check-type-exact $_allOldInput = array<string, mixed> */

    $_remember = $session->remember('key', fn () => 'value');
    /** @psalm-check-type-exact $_remember = mixed */

    $_previousUrl = $session->previousUrl();
    /** @psalm-check-type-exact $_previousUrl = null|string */
}
?>
--EXPECTF--
