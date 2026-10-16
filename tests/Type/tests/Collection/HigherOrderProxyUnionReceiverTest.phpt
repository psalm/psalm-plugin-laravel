--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

final class UnionProxyA
{
    public function onlyA(): int
    {
        return 1;
    }

    public function enabled(): bool
    {
        return true;
    }
}

final class UnionProxyB
{
    public function enabled(): bool
    {
        return false;
    }
}

/**
 * A receiver union of two collection types is not narrowed through a higher-order proxy:
 * picking one collection atomic would silently drop the other branch's item type, so a method
 * only one branch has stays unresolved (mixed) instead of being typed from the first branch.
 */
final class HigherOrderProxyUnionReceiverTest
{
    /**
     * @param Collection<int, UnionProxyA>|LazyCollection<int, UnionProxyB> $items
     */
    public function unionReceiver($items): void
    {
        $_sole = $items->sole->enabled();
        /** @psalm-check-type-exact $_sole = bool */

        $_first = $items->first->enabled();
        /** @psalm-check-type-exact $_first = bool */

        $_onlyA = $items->sole->onlyA();
        /** @psalm-check-type-exact $_onlyA = mixed */
    }
}
?>
--EXPECTF--
