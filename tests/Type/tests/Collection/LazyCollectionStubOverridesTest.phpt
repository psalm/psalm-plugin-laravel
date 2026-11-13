--FILE--
<?php declare(strict_types=1);

/**
 * Laravel declares these LazyCollection methods with `{@inheritDoc}`, and Psalm does not carry
 * Enumerable's templates through it: params degrade to generated templates and reject ordinary
 * arguments (InvalidArgument). The stub redeclares them; groupBy() groups stay eager Collections
 * (runtime is passthru() over collect()->groupBy()).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1894
 */

use App\Models\CollectionGroupingModel as M;
use Illuminate\Support\LazyCollection;

/**
 * @param LazyCollection<int, M> $l
 */
function lazy_stub_overrides(LazyCollection $l): void
{
    $_merge = $l->merge(['a' => 1]);
    /** @psalm-check-type-exact $_merge = LazyCollection<'a'|int, 1|M>&static */

    $_groupByColumn = $l->groupBy('foreign_id');
    /** @psalm-check-type-exact $_groupByColumn = LazyCollection<array-key, Illuminate\Support\Collection<int, M>>&static */

    $_groupByClosure = $l->groupBy(static fn (M $m): int => $m->foreign_id, true);
    /** @psalm-check-type-exact $_groupByClosure = LazyCollection<int, Illuminate\Support\Collection<int, M>>&static */

    $_keyByColumn = $l->keyBy('foreign_id');
    /** @psalm-check-type-exact $_keyByColumn = LazyCollection<array-key, M>&static */

    $_keyByClosure = $l->keyBy(static fn (M $m): int => $m->foreign_id);
    /** @psalm-check-type-exact $_keyByClosure = LazyCollection<int, M>&static */

    $_crossJoin = $l->crossJoin([1, 2]);
    /** @psalm-check-type-exact $_crossJoin = LazyCollection<int, array<int, 1|2|M>>&static */

    $_dictionary = $l->mapToDictionary(static fn (M $m): array => [$m->foreign_id => $m->active]);
    /** @psalm-check-type-exact $_dictionary = LazyCollection<int, array<int, bool>>&static */

    $_duplicates = $l->duplicates(static fn (M $m): int => $m->foreign_id);
    /** @psalm-check-type-exact $_duplicates = LazyCollection<int, int>&static */

    $_duplicatesStrict = $l->duplicatesStrict('foreign_id');
    /** @psalm-check-type-exact $_duplicatesStrict = LazyCollection<int, mixed>&static */
}
--EXPECTF--
