--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Known limitation, see the diff/intersect group comment in stubs/common/Support/Collection.phpstub.
 *
 * The base stub accepts any iterable for diff()/intersect(), but Eloquent\Collection narrows the
 * parameter to models and compares by key. A receiver typed as the base class may be an Eloquent
 * collection at runtime, so `diff([1])` is accepted here and fails only at runtime.
 */
final class CollectionDiffEloquentReceiverKnownLimitation
{
    /** @param Collection<int, Model> $models */
    public function diffAcceptsScalars(Collection $models): void
    {
        $_diff = $models->diff([1]);
        /** @psalm-check-type-exact $_diff = Collection<int, Model>&static */

        $_intersect = $models->intersect([1]);
        /** @psalm-check-type-exact $_intersect = Collection<int, Model>&static */
    }
}
--EXPECTF--
