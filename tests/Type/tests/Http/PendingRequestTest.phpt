--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Client\Promises\LazyPromise;

// TAsync=true after async(): returns LazyPromise only
function test_async(PendingRequest $request): void {
    $_get = $request->async()->get('https://example.com');
    /** @psalm-check-type-exact $_get = LazyPromise */

    $_post = $request->async()->post('https://example.com');
    /** @psalm-check-type-exact $_post = LazyPromise */

    $_send = $request->async()->send('GET', 'https://example.com');
    /** @psalm-check-type-exact $_send = LazyPromise */
}

// TAsync=false after async(false): returns Response only
function test_explicit_sync(PendingRequest $request): void {
    $_result = $request->async(false)->get('https://example.com');
    /** @psalm-check-type-exact $_result = Response */
}

// Unresolved TAsync (bare PendingRequest param uses bound `bool`): returns union
function test_unresolved_template(PendingRequest $request): void {
    $_result = $request->get('https://example.com');
    /** @psalm-check-type-exact $_result = LazyPromise|Response */
}
?>
--EXPECTF--
