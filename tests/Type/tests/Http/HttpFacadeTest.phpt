--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Client\AsyncPendingRequest;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * `Http::verb()` is typed like the PendingRequest call the facade root forwards to: the facade
 * stub's `@mixin PendingRequest` outranks Laravel's `Response|PromiseInterface` `@method` tags,
 * and the params are the PendingRequest stub's (null, any object, list bodies).
 *
 * @param array<array-key, mixed> $p
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1669
 */
function test_http_facade_verbs(string $u, array $p): void
{
    $_get = Http::get($u);
    /** @psalm-check-type-exact $_get = Response */

    $_post = Http::post($u, null);
    /** @psalm-check-type-exact $_post = Response */

    $_put = Http::put($u, new \stdClass());
    /** @psalm-check-type-exact $_put = Response */

    $_patch = Http::patch($u, $p);
    /** @psalm-check-type-exact $_patch = Response */

    $_delete = Http::delete($u);
    /** @psalm-check-type-exact $_delete = Response */

    $_head = Http::head($u, $p);
    /** @psalm-check-type-exact $_head = Response */

    $_send = Http::send('GET', $u, $p);
    /** @psalm-check-type-exact $_send = Response */

    $_alias = \Http::get($u);
    /** @psalm-check-type-exact $_alias = Response */
}

function test_http_facade_async_toggle(string $u): void
{
    $_async = Http::async();
    /** @psalm-check-type-exact $_async = AsyncPendingRequest */

    $_sync = Http::async(false)->get($u);
    /** @psalm-check-type-exact $_sync = Response */
}

function test_http_facade_chains(string $u): void
{
    $_token = Http::withToken('t')->get($u);
    /** @psalm-check-type-exact $_token = Response */

    $_retry = Http::retry(3)->post($u, []);
    /** @psalm-check-type-exact $_retry = Response */

    $_headers = Http::withHeaders(['a' => 'b'])->acceptJson()->get($u);
    /** @psalm-check-type-exact $_headers = Response */

    // Laravel's own pool() element type, which widened from Response (12.27) to Response|Throwable (12.50).
    $_pool = Http::pool(fn (\Illuminate\Http\Client\Pool $pool): array => [$pool->get($u)]);
    /** @psalm-check-type $_pool = array<array-key, Response|\Throwable> */
}

/** Factory-owned methods keep the facade root's types; the mixin does not reach them. */
function test_http_facade_factory_methods(): void
{
    $_fake = Http::fake();
    /** @psalm-check-type-exact $_fake = Factory */

    $_prevent = Http::preventStrayRequests();
    /** @psalm-check-type-exact $_prevent = Factory */

    $_allow = Http::allowStrayRequests();
    /** @psalm-check-type-exact $_allow = Factory */

    $_allowOnly = Http::allowStrayRequests(['https://x.test/*']);
    /** @psalm-check-type-exact $_allowOnly = Factory */

    $_pending = Http::createPendingRequest();
    /** @psalm-check-type-exact $_pending = PendingRequest */

    Http::macro('probe', fn (): int => 1);
    Http::assertSentCount(0);
}
?>
--EXPECTF--
