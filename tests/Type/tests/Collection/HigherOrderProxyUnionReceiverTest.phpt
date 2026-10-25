--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
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

final class UnionProxyModel extends Model
{
}

final class UnionProxyOtherProxy
{
    public function enabled(): string
    {
        return '';
    }
}

final class UnionProxyOther
{
    public function __construct(public UnionProxyOtherProxy $first) {}
}

/**
 * @template V
 * @template K of array-key
 * @extends Collection<K, V>
 */
final class UnionProxyReversed extends Collection
{
}

/** @extends Collection<int, UnionProxyB> */
final class UnionProxyListB extends Collection
{
}

/**
 * A receiver that is a union of several collection types is resolved branch by branch through a
 * higher-order proxy: each collection atomic is typed with its own TKey/TValue/class and the results
 * are combined, so the union keeps what the proxied collection method adds (first/last add null).
 * A branch whose item type cannot be read (a non-generic subclass) makes the whole answer mixed.
 * The `$sole` proxy (Laravel 13.33+) is covered in HigherOrderSoleProxyTest.
 */
final class HigherOrderProxyUnionReceiverTest
{
    /**
     * @param Collection<int, UnionProxyA>|LazyCollection<int, UnionProxyB> $items
     */
    public function unionReceiver($items): void
    {
        // first() returns the item (or null), not the proxied method's result
        $_first = $items->first->enabled();
        /** @psalm-check-type-exact $_first = UnionProxyA|UnionProxyB|null */

        $_onlyA = $items->first->onlyA();
        /** @psalm-check-type-exact $_onlyA = UnionProxyA|UnionProxyB|null */

        $_each = $items->each->enabled();
        /** @psalm-check-type-exact $_each = Collection<int, UnionProxyA>|LazyCollection<int, UnionProxyB> */

        $_map = $items->map->enabled();
        /** @psalm-check-type-exact $_map = Collection<int, bool>|LazyCollection<int, bool> */

        $_sum = $items->sum->enabled();
        /** @psalm-check-type-exact $_sum = float|int */

        // a proxy the handler does not model declines: Psalm's own @mixin result stays
        $_unmodelled = $items->when->enabled();
        /** @psalm-check-type-exact $_unmodelled = bool */
    }

    /**
     * @param Collection<int, UnionProxyA>|LazyCollection<int, UnionProxyB> $items
     */
    public function unionLast($items): void
    {
        $_last = $items->last->enabled();
        /** @psalm-check-type-exact $_last = UnionProxyA|UnionProxyB|null */
    }

    /**
     * @param Collection<int, UnionProxyA>|LazyCollection<string, UnionProxyB> $items
     */
    public function unionWithDifferingKeys($items): void
    {
        $_each = $items->each->enabled();
        /** @psalm-check-type-exact $_each = Collection<int, UnionProxyA>|LazyCollection<string, UnionProxyB> */

        $_map = $items->map->enabled();
        /** @psalm-check-type-exact $_map = Collection<int, bool>|LazyCollection<string, bool> */
    }

    /**
     * A non-collection branch has its own proxy shape, so the receiver is not modelled: Psalm's
     * result for every branch stays.
     *
     * @param Collection<int, UnionProxyA>|LazyCollection<int, UnionProxyB>|UnionProxyOther $items
     */
    public function unionWithNonCollectionBranch($items): void
    {
        $_first = $items->first->enabled();
        /** @psalm-check-type-exact $_first = bool|string */
    }

    /**
     * @template T of Collection<int, UnionProxyA>
     * @param Collection<int, UnionProxyA>|LazyCollection<int, UnionProxyB>|T $items
     */
    public function unionWithTemplateBranch($items): void
    {
        $_first = $items->first->enabled();
        /** @psalm-check-type-exact $_first = bool */
    }

    /**
     * A subclass that reorders its templates is positionally unreadable, so the answer is mixed.
     *
     * @param Collection<int, UnionProxyA>|UnionProxyReversed<UnionProxyB, int> $items
     */
    public function unionWithReorderedTemplates($items): void
    {
        $_first = $items->first->enabled();
        /** @psalm-check-type-exact $_first = mixed */

        $_each = $items->each->enabled();
        /** @psalm-check-type-exact $_each = mixed */
    }

    /**
     * @param Collection<int, UnionProxyA>|EloquentCollection<int, UnionProxyModel> $items
     */
    public function unionWithEloquentCollection($items): void
    {
        $_first = $items->first->getKey();
        /** @psalm-check-type-exact $_first = UnionProxyA|UnionProxyModel|null */
    }

    /**
     * A non-generic subclass branch (a named object) counts as a collection too, and its item type
     * is not readable from the type, so the answer is mixed instead of the raw mixin result.
     *
     * @param Collection<int, UnionProxyA>|UnionProxyListB $items
     */
    public function unionWithNonGenericSubclass($items): void
    {
        $_enabled = $items->first->enabled();
        /** @psalm-check-type-exact $_enabled = mixed */

        $_onlyA = $items->first->onlyA();
        /** @psalm-check-type-exact $_onlyA = mixed */

        $_each = $items->each->enabled();
        /** @psalm-check-type-exact $_each = mixed */
    }
}
?>
--EXPECTF--
