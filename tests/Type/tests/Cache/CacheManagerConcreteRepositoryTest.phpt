--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Cache\CacheManager;
use Illuminate\Support\Facades\Cache;

/**
 * store()/driver()/memo() declare the Repository interface but always return the
 * concrete \Illuminate\Cache\Repository, so its concrete-only surface (flexible(),
 * tags(), Macroable helpers) must resolve on every access path. See issue #1230.
 */
class CustomCacheManager extends CacheManager
{
}

function issue_repro(): array
{
    return Cache::driver('file')->flexible('things', [300, 3600], static function (): array {
        return ['a', 'b'];
    });
}

function facade(): void
{
    $_store = Cache::store();
    /** @psalm-check-type-exact $_store = \Illuminate\Cache\Repository */

    $_driver = Cache::driver();
    /** @psalm-check-type-exact $_driver = \Illuminate\Cache\Repository */

    $_memo = Cache::memo();
    /** @psalm-check-type-exact $_memo = \Illuminate\Cache\Repository */
}

function alias(): void
{
    $_driver = \Cache::driver();
    /** @psalm-check-type-exact $_driver = \Illuminate\Cache\Repository */
}

function injected(CacheManager $manager): void
{
    $_store = $manager->store();
    /** @psalm-check-type-exact $_store = \Illuminate\Cache\Repository */

    $_flexible = $manager->driver()->flexible('things', [300, 3600], static fn (): int => 1);
    /** @psalm-check-type-exact $_flexible = 1 */
}

function subclass(CustomCacheManager $manager): void
{
    $_driver = $manager->driver();
    /** @psalm-check-type-exact $_driver = \Illuminate\Cache\Repository */
}

function helper(): void
{
    $_driver = cache()->driver();
    /** @psalm-check-type-exact $_driver = \Illuminate\Cache\Repository */
}
?>
--EXPECTF--
