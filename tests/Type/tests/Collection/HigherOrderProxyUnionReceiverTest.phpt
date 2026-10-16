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

/** @extends Collection<int, UnionProxyB> */
final class UnionProxyListB extends Collection
{
}

/**
 * A receiver union of two collection types is not narrowed through a higher-order proxy:
 * picking one collection atomic would silently drop the other branch's item type, so a method
 * only one branch has stays unresolved (mixed) instead of being typed from the first branch.
 * The `$sole` proxy (Laravel 13.33+) is covered in HigherOrderSoleProxyTest.
 */
final class HigherOrderProxyUnionReceiverTest
{
    /**
     * @param Collection<int, UnionProxyA>|LazyCollection<int, UnionProxyB> $items
     */
    public function unionReceiver($items): void
    {
        $_first = $items->first->enabled();
        /** @psalm-check-type-exact $_first = bool */

        $_onlyA = $items->first->onlyA();
        /** @psalm-check-type-exact $_onlyA = mixed */
    }

    /**
     * A non-generic subclass branch (a named object) counts as a collection too.
     *
     * @param Collection<int, UnionProxyA>|UnionProxyListB $items
     */
    public function unionWithNonGenericSubclass($items): void
    {
        $_enabled = $items->first->enabled();
        /** @psalm-check-type-exact $_enabled = mixed */

        $_onlyA = $items->first->onlyA();
        /** @psalm-check-type-exact $_onlyA = mixed */
    }
}
?>
--EXPECTF--
