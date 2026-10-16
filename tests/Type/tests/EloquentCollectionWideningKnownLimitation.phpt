--FILE--
<?php declare(strict_types=1);

use App\Collections\InvoiceCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Known limitation, see the diff/intersect group comment in stubs/common/Support/Collection.phpstub.
 *
 * The base stub accepts any iterable for diff()/intersect(), but Eloquent\Collection narrows the
 * parameter to models and compares by key. A receiver typed as the base class may be an Eloquent
 * collection at runtime, so `diff([1])` is accepted here and fails only at runtime.
 *
 * Second gap: the Eloquent\Collection union/replace/replaceRecursive/mergeRecursive redeclarations
 * do not reach user subclasses, because Psalm keeps their declaring method at Support\Collection
 * (vimeo/psalm#12158), so `union([1])` on a custom collection passes. See the "Collection read-only
 * inputs" entry in docs/contributing/decisions.md; this pin flips when the subclass inheritance
 * repair lands.
 */
final class EloquentCollectionWideningKnownLimitation
{
    /** @param Collection<int, Model> $models */
    public function diffAcceptsScalars(Collection $models): void
    {
        $_diff = $models->diff([1]);
        /** @psalm-check-type-exact $_diff = Collection<int, Model>&static */

        $_intersect = $models->intersect([1]);
        /** @psalm-check-type-exact $_intersect = Collection<int, Model>&static */
    }

    public function subclassAcceptsScalars(InvoiceCollection $invoices): void
    {
        $_union = $invoices->union([1]);
        /** @psalm-check-type-exact $_union = InvoiceCollection */
    }
}
--EXPECTF--
