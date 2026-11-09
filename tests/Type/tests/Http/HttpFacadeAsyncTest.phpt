--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('12.42.0');
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Client\Batch;
use Illuminate\Http\Client\Promises\LazyPromise;
use Illuminate\Support\Facades\Http;

/**
 * `Http::async()` reaches the stub-only AsyncPendingRequest through the facade's `@mixin`, so the
 * verbs after it are promises, never the sync Response Laravel's `@method async()` tag implied.
 * `batch()` (12.35+) resolves to PendingRequest's like the other forwarded methods.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1669
 */
function test_http_facade_async(string $u): void
{
    $_get = Http::async()->get($u);
    /** @psalm-check-type-exact $_get = LazyPromise */

    $_send = Http::async()->send('GET', $u);
    /** @psalm-check-type-exact $_send = LazyPromise */

    $_chained = Http::async()->withToken('t')->post($u);
    /** @psalm-check-type-exact $_chained = LazyPromise */

    $_batch = Http::batch(fn (Batch $batch): array => [$batch->get($u)]);
    /** @psalm-check-type-exact $_batch = Batch */
}
?>
--EXPECTF--
