--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\PsalmVersion::skipOnRange('7.0.0-rc16', '7.0.0-rc26');
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;

final class PropFetchIssetItem
{
    public ?PropFetchIssetItem $parent = null;
}

/**
 * Known limitation: the proxy declares no `__isset()`, so a direct isset()/empty() operand `$c->map->x` is never set
 * at runtime (isset() false, empty() true). Such an operand keeps Psalm's item-level type (see
 * HigherOrderCollectionProxyHandler::PRESENCE_TARGET_ATTRIBUTE), and the scope entry Psalm writes there keeps a
 * later plain read of the same fetch at that item-level type too. A fetch already read in the same scope holds the
 * never-null proxy result, which Psalm then reports as always set.
 *
 * @param Collection<int, PropFetchIssetItem> $p
 */
function issetOnProxyFetch(Collection $p): void
{
    // Item-level `PropFetchIssetItem|null`, so no "always isset" report here.
    $_isset = isset($p->map->parent) ? 'set' : 'unset';

    $_empty = empty($p->keyBy->parent);
    $_afterEmpty = $p->keyBy->parent;
    /** @psalm-check-type-exact $_afterEmpty = PropFetchIssetItem|null */

    $_read = $p->filter->parent;
    $_reread = isset($p->filter->parent) ? 'set' : 'unset';
}
?>
--EXPECTF--
RedundantCondition on line %d: Type Illuminate\Support\Collection<int, PropFetchIssetItem> for $p->filter->parent is always isset
TypeDoesNotContainType on line %d: Cannot resolve types for $p->filter->parent with type Illuminate\Support\Collection<int, PropFetchIssetItem> and !isset assertion
