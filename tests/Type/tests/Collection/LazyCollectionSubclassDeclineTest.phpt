--CONFLICTS--
psalm-1894-LazyCollectionSubclassDecline
--FILE--
<?php declare(strict_types=1);

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1894
 *
 * Own Psalm run: declaring a LazyCollection subclass in a shared batch makes Psalm drop
 * TooManyArguments for the inherited methods in other fixtures (Variadic/AuditRejectionsTest).
 */

use App\Models\CollectionGroupingModel as M;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * @template TKey of array-key
 * @template-covariant TValue
 * @extends LazyCollection<TKey, TValue>
 */
final class LazyChild extends LazyCollection {}

/**
 * Template slots deliberately reordered against the parent: slot 1 is not the parent's TValue.
 *
 * @template-covariant TValue
 * @template TDecoy
 * @extends LazyCollection<int, TValue>
 */
final class LazyDecoy extends LazyCollection {}

/**
 * The handlers answer for exactly LazyCollection: a subclass may reorder or rename its own
 * template slots, so they decline and the stub types apply.
 *
 * @param LazyChild<int, M> $child
 * @param LazyDecoy<array{foreign_id: float}, M> $decoy
 */
function lazy_subclasses_decline(LazyChild $child, LazyDecoy $decoy): void
{
    $_pluck = $child->pluck('foreign_id');
    /** @psalm-check-type-exact $_pluck = LazyChild<array-key, mixed> */
    $_group = $child->groupBy('foreign_id');
    /** @psalm-check-type-exact $_group = LazyChild<array-key, Collection<int, M>> */
    $_key = $child->keyBy('foreign_id');
    /** @psalm-check-type-exact $_key = LazyChild<array-key, M> */
    // A generic Collection subclass is mixed here too: the stub's conditional return does not resolve
    $_sum = $child->sum('foreign_id');
    /** @psalm-check-type-exact $_sum = mixed */

    $_dkey = $decoy->keyBy('foreign_id');
    /** @psalm-check-type-exact $_dkey = LazyDecoy<array-key, array{foreign_id: float}> */
    $_dpluck = $decoy->pluck('foreign_id');
    /** @psalm-check-type-exact $_dpluck = LazyDecoy<array-key, mixed> */
}
--EXPECTF--
