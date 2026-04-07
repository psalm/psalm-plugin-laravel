--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Client\Promises\LazyPromise;

/**
 * After calling async(), Psalm narrows TAsync to true via @psalm-self-out,
 * so HTTP methods return LazyPromise.
 */
function async_get_returns_promise(PendingRequest $request): LazyPromise {
    return $request->async()->get('https://example.com');
};

function async_post_returns_promise(PendingRequest $request): LazyPromise {
    return $request->async()->post('https://example.com');
};

function async_send_returns_promise(PendingRequest $request): LazyPromise {
    return $request->async()->send('GET', 'https://example.com');
};

/**
 * When TAsync is unresolved (Psalm doesn't narrow class template defaults),
 * sync methods return the union. This is correct — callers receiving a
 * PendingRequest don't know whether async() was called.
 */
function sync_get_returns_union(PendingRequest $request): LazyPromise|Response {
    return $request->get('https://example.com');
};
?>
--EXPECTF--
