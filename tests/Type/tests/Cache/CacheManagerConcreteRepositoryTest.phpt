--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * store()/driver()/memo() are narrowed to the concrete Repository, so
 * concrete-only methods like flexible() resolve without errors (#1230).
 */
function issue_repro(): array
{
    return Cache::driver('file')->flexible('things', [300, 3600], static function (): array {
        return ['a', 'b'];
    });
}

function facade_returns_concrete(): void
{
    $_store = Cache::store();
    /** @psalm-check-type-exact $_store = \Illuminate\Cache\Repository */

    $_driver = Cache::driver();
    /** @psalm-check-type-exact $_driver = \Illuminate\Cache\Repository */

    $_memo = Cache::memo();
    /** @psalm-check-type-exact $_memo = \Illuminate\Cache\Repository */
}

function manager_returns_concrete(CacheManager $manager): void
{
    $_store = $manager->store();
    /** @psalm-check-type-exact $_store = \Illuminate\Cache\Repository */

    $_driver = $manager->driver();
    /** @psalm-check-type-exact $_driver = \Illuminate\Cache\Repository */

    $_memo = $manager->memo();
    /** @psalm-check-type-exact $_memo = \Illuminate\Cache\Repository */

    $_flexible = $manager->driver()->flexible('things', [300, 3600], static fn (): int => 1);
    /** @psalm-check-type-exact $_flexible = 1 */
}

function helper_returns_concrete(): void
{
    $_driver = cache()->driver();
    /** @psalm-check-type-exact $_driver = \Illuminate\Cache\Repository */
}
?>
--EXPECTF--
