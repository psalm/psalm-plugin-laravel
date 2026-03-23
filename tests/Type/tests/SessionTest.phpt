--FILE--
<?php declare(strict_types=1);

use Illuminate\Session\Store;

function session_store_get(Store $session): mixed
{
    return $session->get('key');
}

function session_store_get_with_default(Store $session): mixed
{
    return $session->get('key', 'default');
}

function session_store_pull(Store $session): mixed
{
    return $session->pull('key');
}

function session_store_remove(Store $session): mixed
{
    return $session->remove('key');
}

/** @return array */
function session_store_all(Store $session): array
{
    return $session->all();
}

/** @return array */
function session_store_only(Store $session): array
{
    return $session->only(['key1', 'key2']);
}

/** @return array */
function session_store_except(Store $session): array
{
    return $session->except(['key1']);
}

function session_store_get_old_input(Store $session): mixed
{
    return $session->getOldInput('field');
}

function session_store_remember(Store $session): mixed
{
    return $session->remember('key', fn () => 'default');
}
?>
--EXPECT--
