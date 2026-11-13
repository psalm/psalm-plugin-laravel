--FILE--
<?php declare(strict_types=1);

/**
 * diff()/intersect() only compare their input and return a subset of the receiver, so the input is
 * not bound to TKey/TValue. union()/replace() store the input's entries, so the result widens to
 * the union of both sides' key and value types instead of rejecting a foreign input.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1892
 */

use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\LazyCollection;

/**
 * @param Collection<int, string> $c
 * @param LazyCollection<int, string> $l
 * @param Enumerable<int, string> $e
 * @param Collection<string, int> $other
 */
function diff_intersect(Collection $c, LazyCollection $l, Enumerable $e, Collection $other): void
{
    $_cd = $c->diff($other);
    /** @psalm-check-type-exact $_cd = Collection<int, string>&static */
    $_cd2 = $c->diff([1, 2]);
    /** @psalm-check-type-exact $_cd2 = Collection<int, string>&static */
    $_ci = $c->intersect($other);
    /** @psalm-check-type-exact $_ci = Collection<int, string>&static */
    $_ld = $l->diff($other);
    /** @psalm-check-type-exact $_ld = LazyCollection<int, string>&static */
    $_li = $l->intersect([1]);
    /** @psalm-check-type-exact $_li = LazyCollection<int, string>&static */
    $_ed = $e->diff($other);
    /** @psalm-check-type-exact $_ed = Enumerable<int, string>&static */
    $_ei = $e->intersect([1]);
    /** @psalm-check-type-exact $_ei = Enumerable<int, string>&static */
}

/**
 * @param Collection<int, string> $c
 * @param LazyCollection<int, string> $l
 * @param Enumerable<int, string> $e
 * @param Collection<string, int> $other
 */
function union_replace(Collection $c, LazyCollection $l, Enumerable $e, Collection $other): void
{
    $_cu = $c->union($other);
    /** @psalm-check-type-exact $_cu = Collection<int|string, int|string>&static */
    $_cr = $c->replace(['a' => 1]);
    /** @psalm-check-type-exact $_cr = Collection<'a'|int, 1|string>&static */
    $_lu = $l->union($other);
    /** @psalm-check-type-exact $_lu = LazyCollection<int|string, int|string>&static */
    $_lr = $l->replace(['a' => 1]);
    /** @psalm-check-type-exact $_lr = LazyCollection<'a'|int, 1|string>&static */
    $_eu = $e->union($other);
    /** @psalm-check-type-exact $_eu = Enumerable<int|string, int|string>&static */
    $_er = $e->replace(['a' => 1]);
    /** @psalm-check-type-exact $_er = Enumerable<'a'|int, 1|string>&static */
}
--EXPECTF--
