--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\PsalmVersion::skipOnRange('7.0.0-rc16', '7.0.0-rc26');
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\LazyCollection;

final class PropFetchNegItem
{
    public int $n = 1;
}

/** A class with its own `$map`/`$first` properties: never treated as a proxy. */
final class PropFetchNegOwnMap
{
    public function __construct(public PropFetchNegItem $map, public PropFetchNegItem $first) {}
}

/**
 * @template V
 * @template K of array-key
 * @extends Collection<K, V>
 */
final class PropFetchNegReversed extends Collection {}

/**
 * @template K of array-key
 * @template V
 * @template TExtra
 * @extends Collection<K, V>
 */
final class PropFetchNegExtra extends Collection {}

/**
 * Receivers the handler cannot read positionally get the proxy mapping over `Enumerable` (a supertype of every
 * collection class), never a positional guess; anything that is not only the proxy keeps Psalm's own result.
 */
final class HigherOrderProxyPropertyFetchNegativeTest
{
    /**
     * @template T of Collection<int, PropFetchNegItem>
     * @param T $t
     */
    public function templateReceiver($t): void
    {
        $_n = $t->map->n;
        /** @psalm-check-type-exact $_n = Enumerable<int, int> */
    }

    /**
     * A template bound by a union of collections maps each proxy branch over `Enumerable` and combines them.
     *
     * @template T of Collection<int, PropFetchNegItem>|LazyCollection<string, PropFetchNegItem>
     * @template A of Collection<int, array{n: int}>|LazyCollection<string, array{n: string}>
     * @param T $t
     * @param A $a
     */
    public function templateUnionReceiver($t, $a): void
    {
        $_map = $t->map->n;
        /** @psalm-check-type-exact $_map = Enumerable<int, int>|Enumerable<string, int> */
        $_filter = $t->filter->n;
        /** @psalm-check-type-exact $_filter = Enumerable<int, PropFetchNegItem>|Enumerable<string, PropFetchNegItem> */
        $_arrayMap = $a->map->n;
        /** @psalm-check-type-exact $_arrayMap = Enumerable<int, int|string>|Enumerable<string, int|string> */
    }

    /**
     * @param PropFetchNegReversed<PropFetchNegItem, int> $reversed
     * @param PropFetchNegExtra<int, PropFetchNegItem, string> $extra
     */
    public function reshapedTemplateSubclasses(PropFetchNegReversed $reversed, PropFetchNegExtra $extra): void
    {
        $_reversed = $reversed->map->n;
        /** @psalm-check-type-exact $_reversed = Enumerable<array-key, mixed> */
        $_extra = $extra->map->n;
        /** @psalm-check-type-exact $_extra = Enumerable<array-key, mixed> */
    }

    public function ownMapProperty(PropFetchNegOwnMap $own): void
    {
        $_n = $own->map->n;
        /** @psalm-check-type-exact $_n = int */
    }

    /** @param Collection<int, PropFetchNegItem>|PropFetchNegOwnMap $mixed */
    public function receiverWithNonCollectionBranch($mixed): void
    {
        $_n = $mixed->map->n;
        /** @psalm-check-type-exact $_n = int */
    }

    /** @param Collection<int, PropFetchNegItem> $p */
    public function unmodelledProxy(Collection $p): void
    {
        $_when = $p->when->n;
        /** @psalm-check-type-exact $_when = int */
    }

    /**
     * A variable-held proxy is not recognized (same as the method-call path).
     *
     * @param Collection<int, PropFetchNegItem> $p
     */
    public function variableHeldProxy(Collection $p): void
    {
        $proxy = $p->map;
        $_n = $proxy->n;
        /** @psalm-check-type-exact $_n = int */
    }

    /**
     * Psalm's `__get` stand-in for a variable-held proxy answers the item level only, which is unknown here.
     *
     * @param Collection<int, PropFetchNegItem> $p
     */
    public function variableHeldProxyUndefinedProperty(Collection $p): void
    {
        $proxy = $p->map;
        $_undefined = $proxy->undefined_thing;
        /** @psalm-check-type-exact $_undefined = mixed */
    }
}
?>
--EXPECTF--
