--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class EmptinessLimitBadge
{
}

/**
 * Known limitations of the `isEmpty()` / `isNotEmpty()` assertions in stubs/common/Support/Collection.phpstub
 * (see the `isEmpty()` docblock there). These pin today's Psalm behaviour; update them when it changes.
 *
 * 1. Inside the true branch of `isEmpty()`, a `class-string<X>` TValue reads `null|string`, not `null`.
 *    Psalm's NegatedAssertionReconciler adds `string` when reconciling any `!class-string` negation, even
 *    if the existing type has no string. The narrowing of the false branch (no `UndefinedClass`) is unaffected.
 * 2. The narrowing survives calls that mutate the collection through another reference: Psalm keeps memoized
 *    method-call results across function calls (rememberPropertyAssignmentsAfterCall). The old
 *    `isEmpty()` -> `null` assertion had the same gap.
 * 3. UNSOUND: for a receiver union, Psalm overwrites the call's assertions per receiver alternative
 *    (ExistingAtomicMethodCallAnalyzer), so only the last alternative's assertion applies and the result
 *    depends on the union order: `Collection<string|null>|EloquentCollection<Model>` loses null,
 *    the reversed order keeps it.
 * 4. `isNotEmpty()` asserts only `count()`: a `TValue` assertion on first()/last() would collapse a receiver
 *    union (`Collection<int, string>|Collection<int, int>` gives `int`) for the same per-alternative reason,
 *    and a plain generic class reproduces it. first() therefore stays nullable after the guard.
 */
final class CollectionEmptinessAssertionsKnownLimitation
{
    /** @param Collection<int, class-string<EmptinessLimitBadge>> $classes */
    public function classStringInsideIsEmptyBranch(Collection $classes): void
    {
        if ($classes->isEmpty()) {
            $_first = $classes->first();
            /** @psalm-check-type-exact $_first = null|string */
        }
    }

    /** @param Collection<int, string|null>|EloquentCollection<int, Model> $items */
    public function receiverUnionLosesNull(Collection|EloquentCollection $items): void
    {
        if ($items->isEmpty()) {
            return;
        }

        $_first = $items->first();
        /** @psalm-check-type-exact $_first = Illuminate\Database\Eloquent\Model|string */
    }

    /** @param EloquentCollection<int, Model>|Collection<int, string|null> $items */
    public function reversedReceiverUnionLosesNull(Collection|EloquentCollection $items): void
    {
        if ($items->isEmpty()) {
            return;
        }

        $_first = $items->first();
        /** @psalm-check-type-exact $_first = Illuminate\Database\Eloquent\Model|null|string */
    }

    /** @param Collection<int, string>|Collection<int, int> $items */
    public function isNotEmptyKeepsFirstUnassertedForReceiverUnion(Collection $items): void
    {
        if ($items->isNotEmpty()) {
            $_count = $items->count();
            /** @psalm-check-type-exact $_count = int<1, max> */

            $_first = $items->first();
            /** @psalm-check-type-exact $_first = int|null|string */
        }
    }

    /** @param Collection<int, EmptinessLimitBadge> $badges */
    public function narrowingSurvivesMutationThroughAnotherReference(Collection $badges): void
    {
        if ($badges->isEmpty()) {
            return;
        }

        self::drain($badges);

        $_first = $badges->first();
        /** @psalm-check-type-exact $_first = EmptinessLimitBadge */
    }

    /** @param Collection<int, EmptinessLimitBadge> $badges */
    private static function drain(Collection $badges): void
    {
        $badges->pop();
    }
}
?>
--EXPECTF--
