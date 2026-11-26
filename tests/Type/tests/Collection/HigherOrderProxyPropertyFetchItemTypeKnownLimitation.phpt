--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

final class PropFetchLimitItem
{
    public int $n = 1;
}

final class PropFetchLimitOtherItem
{
    public string $n = '';
}

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
}
?>
--EXPECTF--
