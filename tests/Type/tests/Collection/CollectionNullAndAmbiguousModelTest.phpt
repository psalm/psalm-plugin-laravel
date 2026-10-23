--FILE--
<?php declare(strict_types=1);

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1894
 *
 * The "exactly one proven model" rule treats a `null` element or a nullsafe short-circuit as neutral,
 * while a non-null non-model atomic or an ambiguous model slot still declines.
 */

use App\Models\CollectionGroupingModel as M;
use App\Models\Customer;
use App\Models\UnionColumnIntModel;
use App\Models\UnionColumnOtherIntModel;
use App\Models\UnionColumnOwnerModel;
use App\Models\UnionColumnTextModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\LazyCollection;

/**
 * @param Collection<int, M|null> $c
 * @param LazyCollection<int, M|null> $l
 */
function null_elements_keep_the_column_type(Collection $c, LazyCollection $l): void
{
    $_cs = $c->sum('foreign_id');
    /** @psalm-check-type-exact $_cs = int */
    $_ls = $l->sum('foreign_id');
    /** @psalm-check-type-exact $_ls = int */

    $_cp = $c->pluck('nullable_id');
    /** @psalm-check-type-exact $_cp = Collection<int, int|null> */
    $_lp = $l->pluck('nullable_id');
    /** @psalm-check-type-exact $_lp = LazyCollection<int, int|null> */

    // data_get() on a null element yields null
    $_cn = $c->pluck('foreign_id');
    /** @psalm-check-type-exact $_cn = Collection<int, int|null> */
    $_ln = $l->pluck('foreign_id');
    /** @psalm-check-type-exact $_ln = LazyCollection<int, int|null> */
}

/**
 * @param Collection<int, M> $c
 * @param LazyCollection<int, M> $l
 */
function no_null_element_stays_non_null(Collection $c, LazyCollection $l): void
{
    $_c = $c->pluck('foreign_id');
    /** @psalm-check-type-exact $_c = Collection<int, int> */
    $_l = $l->pluck('foreign_id');
    /** @psalm-check-type-exact $_l = LazyCollection<int, int> */
}

/**
 * @param Collection<int, M|null>|Collection<string, M|null> $items
 * @return Collection<int, int|null>
 */
function outer_union_of_nullable_collections(Collection $items): Collection
{
    return $items->pluck('nullable_id');
}

/**
 * @param LazyCollection<int, M|null>|LazyCollection<string, M|null> $items
 * @return LazyCollection<int, int|null>
 */
function outer_union_of_nullable_lazy_collections(LazyCollection $items): LazyCollection
{
    return $items->pluck('nullable_id');
}

/**
 * @param Collection<int, M|null>|EloquentCollection<int, M> $items
 * @return Collection<int, int|null>
 */
function outer_union_of_collection_and_eloquent_collection(Collection $items): Collection
{
    return $items->pluck('nullable_id');
}

/** @return Collection<int, string>|null */
function nullsafe_relation_chain_pluck(Customer $customer): ?Collection
{
    return $customer->primary_vehicle?->customer()->pluck('id');
}

/** @return Collection<int, string>|null */
function nullsafe_builder_chain_pluck(Customer $customer): ?Collection
{
    return $customer->primary_vehicle?->newQuery()->pluck('make');
}

/**
 * MorphTo<A|B, Owner>: the aggregate unions A's and B's column and never reads Owner's (float) column.
 * Mixed numeric shapes (int|string) are not narrowed by sum() and fall back to the stub.
 */
function morph_to_union_aggregates(UnionColumnOwnerModel $owner): void
{
    $_sum = $owner->intTarget()->sum('rank');
    /** @psalm-check-type-exact $_sum = int */
    $_min = $owner->intTarget()->min('rank');
    /** @psalm-check-type-exact $_min = int|null */
    $_mixedMin = $owner->mixedTarget()->min('rank');
    /** @psalm-check-type-exact $_mixedMin = int|string|null */
    $_mixedSum = $owner->mixedTarget()->sum('rank');
    /** @psalm-check-type-exact $_mixedSum = float|int|numeric-string */
}

/**
 * A union made only of models resolves the column on each model; non-models still decline.
 *
 * @param Builder<UnionColumnIntModel|UnionColumnOtherIntModel> $ints
 * @param Builder<UnionColumnIntModel|UnionColumnTextModel> $mixed
 * @param Collection<int, UnionColumnIntModel|UnionColumnOtherIntModel> $c
 * @param LazyCollection<int, UnionColumnIntModel|UnionColumnTextModel|null> $l
 */
function union_of_models_pluck(Builder $ints, Builder $mixed, Collection $c, LazyCollection $l): void
{
    $_b = $ints->pluck('rank');
    /** @psalm-check-type-exact $_b = Collection<int, int> */
    $_m = $mixed->pluck('rank');
    /** @psalm-check-type-exact $_m = Collection<int, int|string> */
    $_c = $c->pluck('rank');
    /** @psalm-check-type-exact $_c = Collection<int, int> */
    $_l = $l->pluck('rank');
    /** @psalm-check-type-exact $_l = LazyCollection<int, int|string|null> */
    // A column neither model declares: no value narrowing (the key axis still narrows to int)
    $_u = $ints->pluck('unknown_column');
    /** @psalm-check-type-exact $_u = Collection<int, mixed> */
}

/**
 * @param Collection<int, M> $c
 * @param LazyCollection<int, M> $l
 * @param Enumerable<int, M> $e
 * @param (callable(M): int)|null $callback
 */
function duplicates_null_named_and_nullable_callback(Collection $c, LazyCollection $l, Enumerable $e, ?callable $callback): void
{
    $_c = $c->duplicates(null);
    /** @psalm-check-type-exact $_c = Collection<int, M>&static */
    $_l = $l->duplicates(callback: null);
    /** @psalm-check-type-exact $_l = LazyCollection<int, M>&static */
    $_e = $e->duplicatesStrict(callback: static fn (M $m): int => $m->foreign_id);
    /** @psalm-check-type-exact $_e = Enumerable<int, int>&static */

    $_c2 = $c->duplicates($callback);
    /** @psalm-check-type-exact $_c2 = Collection<int, int|M>&static */
    $_l2 = $l->duplicates($callback);
    /** @psalm-check-type-exact $_l2 = LazyCollection<int, int|M>&static */
}
/**
 * `class-string<A|B>::query()` is `Builder<A>|Builder<B>`: the column resolves on every receiver atomic.
 *
 * @param class-string<UnionColumnIntModel|UnionColumnOtherIntModel> $ints
 * @param class-string<UnionColumnIntModel|UnionColumnTextModel> $mixed
 */
function class_string_union_query_pluck(string $ints, string $mixed): void
{
    $_i = $ints::query()->pluck('rank');
    /** @psalm-check-type-exact $_i = Collection<int, int> */
    $_m = $mixed::query()->pluck('rank');
    /** @psalm-check-type-exact $_m = Collection<int, int|string> */
}

/**
 * A receiver atomic that is not a model collection still declines the whole call.
 *
 * @param Collection<int, UnionColumnIntModel>|Collection<int, array{rank: float}> $c
 */
function mixed_receiver_atomics_decline(Collection $c): void
{
    $_c = $c->pluck('rank');
    /** @psalm-check-type-exact $_c = Collection<array-key, mixed>&static */
}
--EXPECTF--
