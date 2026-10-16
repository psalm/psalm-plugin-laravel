--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\HigherOrderCollectionProxy;

class CompoundKeyItem extends Model {}

/**
 * Declares a compound key mapping: the key is `K|int`, so it names an unresolved template.
 *
 * @template K of array-key
 * @template V
 * @extends Collection<K|int, V>
 */
final class CompoundKeyCollection extends Collection {}

/** @param Enumerable<int, CompoundKeyItem> $_items */
function takesIntKeyedItems(Enumerable $_items): void {}

/**
 * Known limitation (see the caveat on HigherOrderCollectionProxyHandler::enumerableKey()): a compound
 * `@extends` key mapping that still names a template falls back to `array-key` instead of leaking the
 * unresolved `K` into the caller. Valid code is therefore reported with the wider key.
 *
 * @param HigherOrderCollectionProxy<'each', CompoundKeyItem, CompoundKeyCollection<int, CompoundKeyItem>> $proxy
 */
function compound_key_mapping_declines(HigherOrderCollectionProxy $proxy): void
{
    $_result = $proxy->getKey();
    /** @psalm-check-type-exact $_result = Enumerable<array-key, CompoundKeyItem> */
    takesIntKeyedItems($_result);
}
?>
--EXPECTF--
MixedArgumentTypeCoercion on line %d: Argument 1 of takesIntKeyedItems expects Illuminate\Support\Enumerable<int, CompoundKeyItem>, but parent type Illuminate\Support\Enumerable<array-key, CompoundKeyItem> provided
