--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipFrom('13.33.0');
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;

final class SoleFlagBelow
{
    public function enabled(): bool
    {
        return true;
    }
}

/**
 * Companion to HigherOrderSoleProxyTest: before Laravel 13.33.0 `sole` is not a higher-order
 * proxy (`EnumeratesValues::$proxies` lacks it), so `$collection->sole` throws at runtime. The
 * plugin must not type it as a proxy: it stays the untyped `__get()` result.
 *
 * @param Collection<int, SoleFlagBelow> $items
 */
function sole_proxy_is_unknown_below_13_33(Collection $items): void
{
    $items->sole->enabled();
}
?>
--EXPECTF--
MixedMethodCall on line %d: Cannot determine the type of $items->sole when calling method enabled
