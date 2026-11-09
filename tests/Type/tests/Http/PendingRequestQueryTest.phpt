--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.19.0');
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Promises\LazyPromise;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * `query()` (HTTP QUERY, Laravel 13.19.0+) is typed like the other verbs on the instance, async and
 * facade forms (`stubs/13.19.0/Http/Client/PendingRequest.phpstub`).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1669
 */
function test_pending_request_query(PendingRequest $r, string $u): void
{
    $_instance = $r->query($u, null);
    /** @psalm-check-type-exact $_instance = Response */

    $_async = $r->async()->query($u);
    /** @psalm-check-type-exact $_async = LazyPromise */

    $_facade = Http::query($u, new \stdClass());
    /** @psalm-check-type-exact $_facade = Response */

    $_facadeAsync = Http::async()->query($u);
    /** @psalm-check-type-exact $_facadeAsync = LazyPromise */
}
?>
--EXPECTF--
