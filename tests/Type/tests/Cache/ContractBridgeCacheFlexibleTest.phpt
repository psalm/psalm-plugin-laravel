--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * #1230: `CacheManager::driver()`/`store()` return `Contracts\Cache\Repository`,
 * but `flexible()` and `tags()` live only on the concrete `Illuminate\Cache\Repository`.
 * The dynamic contract bridge resolves the contract via the booted container and
 * bridges the concrete's public methods onto it, so none of these raise
 * UndefinedInterfaceMethod.
 */

/**
 * @return array<string>
 */
function facade_driver_flexible(): array
{
    return Cache::driver('file')->flexible('things', [300, 3600], static function (): array {
        return ['a', 'b'];
    });
}

function contract_typed_param_bridges(Repository $cache): void
{
    $cache->flexible('things', [300, 3600], static function (): array {
        return ['a', 'b'];
    });

    $cache->tags('billing');
}

function cache_helper_driver_bridges(): void
{
    cache()->driver()->flexible('things', [300, 3600], static function (): array {
        return ['a', 'b'];
    });
}
?>
--EXPECTF--
