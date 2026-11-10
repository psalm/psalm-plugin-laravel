--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// The union needs PendingRequest's `@template TAsync` (12.43.0+); below that `async($flag)->get()` is `Response`.
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('12.43.0');
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Promises\LazyPromise;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * An unknown `async()` flag keeps both modes on the facade, as on the instance form, instead of
 * silently picking the sync one.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1669
 */
function test_http_facade_async_flag(PendingRequest $r, string $u, bool $flag): void
{
    $_facade = Http::async($flag)->get($u);
    /** @psalm-check-type-exact $_facade = LazyPromise|Response */

    $_instance = $r->async($flag)->get($u);
    /** @psalm-check-type-exact $_instance = LazyPromise|Response */
}
?>
--EXPECTF--
