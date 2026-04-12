--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\AsyncPendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Client\Promises\LazyPromise;

// Sync calls always return Response — no union types
function test_sync(PendingRequest $request): void {
    $_get = $request->get('https://example.com');
    /** @psalm-check-type-exact $_get = Response */

    $_post = $request->post('https://example.com');
    /** @psalm-check-type-exact $_post = Response */

    $_send = $request->send('GET', 'https://example.com');
    /** @psalm-check-type-exact $_send = Response */

    // Fluent chaining on sync preserves Response return
    $_chained = $request->withUrlParameters(['id' => '1'])->get('https://example.com');
    /** @psalm-check-type-exact $_chained = Response */
}

// async() narrows to AsyncPendingRequest; HTTP method return types are version-specific:
//   L < 12.42.0: GuzzleHttp\Promise\PromiseInterface
//   L >= 12.42.0: Illuminate\Http\Client\Promises\LazyPromise
function test_async(PendingRequest $request): void {
    $_asyncRequest = $request->async();
    /** @psalm-check-type-exact $_asyncRequest = AsyncPendingRequest */

    $request->async()->get('https://example.com');
    $request->async()->post('https://example.com');
    $request->async()->send('GET', 'https://example.com');
}

// async(false) reverts to sync PendingRequest
function test_explicit_sync(PendingRequest $request): void {
    $_result = $request->async(false)->get('https://example.com');
    /** @psalm-check-type-exact $_result = Response */
}

// Fluent chaining preserves async semantics
function test_async_chaining(PendingRequest $request): void {
    $request->async()->withUrlParameters(['id' => '1'])->get('https://example.com');
}
?>
--EXPECTF--
