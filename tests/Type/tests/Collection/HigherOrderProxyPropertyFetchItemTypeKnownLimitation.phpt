--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

final class PropFetchLimitItem
{
    public int $n = 1;
    public ?PropFetchLimitItem $parent = null;
}

final class PropFetchLimitOtherItem
{
    public string $n = '';
}

/**
 * @template K of array-key
 * @template V
 * @extends Collection<K, V>
 */
final class PropFetchLimitGeneric extends Collection {}

/**
 * Known limitation: a property fetch through the proxy wraps the item-level type Psalm computed for the fetch,
 * so the wrap is only as precise as that type (see HigherOrderCollectionProxyHandler::afterExpressionAnalysis()
 * and decisions.md "Property fetch through HigherOrderCollectionProxy").
 */
final class HigherOrderProxyPropertyFetchItemTypeKnownLimitation
{
    /**
     * A union of collections is resolved per branch, but every branch gets the fetch's combined item type:
     * ideally `Collection<int, int>|LazyCollection<string, string>`.
     *
     * @param Collection<int, PropFetchLimitItem>|LazyCollection<string, PropFetchLimitOtherItem> $both
     */
    public function unionOfCollections($both): void
    {
        $_map = $both->map->n;
        /** @psalm-check-type-exact $_map = Collection<int, int|string>|LazyCollection<string, int|string> */
        $_max = $both->max->n;
        /** @psalm-check-type-exact $_max = int|null|string */
    }

    /**
     * A generic subclass resolves its proxy from the vendor trait annotation, whose TValue Psalm leaves
     * unresolved, so the item-level value is mixed: ideally `PropFetchLimitGeneric<string, int>`.
     *
     * @param PropFetchLimitGeneric<string, PropFetchLimitItem> $generic
     */
    public function genericSubclass(PropFetchLimitGeneric $generic): void
    {
        $_map = $generic->map->n;
        /** @psalm-check-type-exact $_map = PropFetchLimitGeneric<string, mixed> */
    }

    /**
     * Not a limitation: a nullsafe OUTER fetch (`$c->map?->parent`) resolves like the plain one.
     *
     * @param Collection<int, PropFetchLimitItem> $p
     */
    public function nullsafeOuterFetch(Collection $p): void
    {
        $_parent = $p->map?->parent;
        /** @psalm-check-type-exact $_parent = Collection<int, PropFetchLimitItem|null> */
    }
}
?>
--EXPECTF--
RedundantCondition on line %d: Type Illuminate\Support\HigherOrderCollectionProxy<'map', PropFetchLimitItem, Illuminate\Support\Collection<int, PropFetchLimitItem>> for $p->map is never null
TypeDoesNotContainNull on line %d: Illuminate\Support\HigherOrderCollectionProxy<string, PropFetchLimitItem, Illuminate\Support\Collection<int, PropFetchLimitItem>> does not contain null
