--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.33.0');
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

final class SoleFlag
{
    public function enabled(): bool
    {
        return true;
    }

    public function onlyFlag(): int
    {
        return 1;
    }
}

final class SoleOther
{
    public function enabled(): bool
    {
        return false;
    }
}

/**
 * Laravel 13.33.0 added `sole` to the higher-order proxies; `$sole->method()` calls `sole()`,
 * which returns the selected item itself.
 */
final class HigherOrderSoleProxyTest
{
    /**
     * @param Collection<int, SoleFlag> $items
     * @param LazyCollection<int, SoleFlag> $lazy
     */
    public function soleProxy(Collection $items, LazyCollection $lazy): void
    {
        $_item = $items->sole->enabled();
        /** @psalm-check-type-exact $_item = SoleFlag */

        $_lazyItem = $lazy->sole->enabled();
        /** @psalm-check-type-exact $_lazyItem = SoleFlag */
    }

    /**
     * A receiver union of two collection types combines each branch's item type.
     *
     * @param Collection<int, SoleFlag>|LazyCollection<int, SoleOther> $items
     */
    public function unionReceiver($items): void
    {
        $_onlyFlag = $items->sole->onlyFlag();
        /** @psalm-check-type-exact $_onlyFlag = SoleFlag|SoleOther */
    }
}
?>
--EXPECTF--
