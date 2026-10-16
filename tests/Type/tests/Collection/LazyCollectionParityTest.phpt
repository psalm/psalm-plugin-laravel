--FILE--
<?php declare(strict_types=1);

/**
 * LazyCollection must type every method the same as Collection does (Collection<int, M> vs
 * LazyCollection<int, M>, only the class name differs; groupBy() groups stay eager Collections). Each function runs one call on
 * Collection ($c), LazyCollection ($l) and Enumerable ($e).
 *
 * Laravel's LazyCollection overrides ~36 methods with `{@inheritDoc}`, and Psalm does not carry
 * Enumerable's templates through that, so the Lazy side needs its own stubs and handler coverage.
 *
 * $e pins today's behavior. Where it differs from $c only because the Collection handlers do not
 * serve Enumerable receivers, or because the method is not on the Enumerable interface, the line
 * says so.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1894
 */

use App\Models\CollectionGroupingModel as M;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\LazyCollection;

/**
 * @param Collection<int, M> $c
 * @param LazyCollection<int, M> $l
 * @param Enumerable<int, M> $e
 */
function parity_merge(Collection $c, LazyCollection $l, Enumerable $e): void
{
    $_c = $c->merge([new M()]);
    /** @psalm-check-type-exact $_c = Collection<int, M>&static */
    $_l = $l->merge([new M()]);
    /** @psalm-check-type-exact $_l = LazyCollection<int, M>&static */
    $_e = $e->merge([new M()]);
    /** @psalm-check-type-exact $_e = Enumerable<int, M>&static */

    // The input keys are independent of TKey
    $_c2 = $c->merge(['a' => 1]);
    /** @psalm-check-type-exact $_c2 = Collection<'a'|int, 1|M>&static */
    $_l2 = $l->merge(['a' => 1]);
    /** @psalm-check-type-exact $_l2 = LazyCollection<'a'|int, 1|M>&static */
    $_e2 = $e->merge(['a' => 1]);
    /** @psalm-check-type-exact $_e2 = Enumerable<'a'|int, 1|M>&static */
}

/**
 * @param Collection<int, M> $c
 * @param LazyCollection<int, M> $l
 * @param Enumerable<int, M> $e
 */
function parity_pad_cross_join_map_to_dictionary(Collection $c, LazyCollection $l, Enumerable $e): void
{
    $_c = $c->pad(3, new M());
    /** @psalm-check-type-exact $_c = Collection<int, M>&static */
    $_l = $l->pad(3, new M());
    /** @psalm-check-type-exact $_l = LazyCollection<int, M>&static */
    $_e = $e->pad(3, new M());
    /** @psalm-check-type-exact $_e = Enumerable<int, M>&static */

    $_c2 = $c->pad(3, 0);
    /** @psalm-check-type-exact $_c2 = Collection<int, 0|M>&static */
    $_l2 = $l->pad(3, 0);
    /** @psalm-check-type-exact $_l2 = LazyCollection<int, 0|M>&static */
    $_e2 = $e->pad(3, 0);
    /** @psalm-check-type-exact $_e2 = Enumerable<int, 0|M>&static */

    $_c3 = $c->crossJoin([1, 2]);
    /** @psalm-check-type-exact $_c3 = Collection<int, array<int, 1|2|M>>&static */
    $_l3 = $l->crossJoin([1, 2]);
    /** @psalm-check-type-exact $_l3 = LazyCollection<int, array<int, 1|2|M>>&static */
    $_e3 = $e->crossJoin([1, 2]);
    /** @psalm-check-type-exact $_e3 = Enumerable<int, array<int, 1|2|M>>&static */

    $_c4 = $c->mapToDictionary(static fn (M $m): array => [$m->foreign_id => $m->active]);
    /** @psalm-check-type-exact $_c4 = Collection<int, array<int, bool>>&static */
    $_l4 = $l->mapToDictionary(static fn (M $m): array => [$m->foreign_id => $m->active]);
    /** @psalm-check-type-exact $_l4 = LazyCollection<int, array<int, bool>>&static */
    $_e4 = $e->mapToDictionary(static fn (M $m): array => [$m->foreign_id => $m->active]);
    /** @psalm-check-type-exact $_e4 = Enumerable<int, array<int, bool>>&static */
}

/**
 * @param Collection<int, M> $c
 * @param LazyCollection<int, M> $l
 * @param Enumerable<int, M> $e
 */
function parity_duplicates(Collection $c, LazyCollection $l, Enumerable $e): void
{
    // duplicates() returns the values projected through the callback, keyed by the original keys
    $_c = $c->duplicates(static fn (M $m): int => $m->foreign_id);
    /** @psalm-check-type-exact $_c = Collection<int, int>&static */
    $_l = $l->duplicates(static fn (M $m): int => $m->foreign_id);
    /** @psalm-check-type-exact $_l = LazyCollection<int, int>&static */
    $_e = $e->duplicates(static fn (M $m): int => $m->foreign_id);
    /** @psalm-check-type-exact $_e = Enumerable<int, int>&static */

    $_c2 = $c->duplicatesStrict(static fn (M $m): int => $m->foreign_id);
    /** @psalm-check-type-exact $_c2 = Collection<int, int>&static */
    $_l2 = $l->duplicatesStrict(static fn (M $m): int => $m->foreign_id);
    /** @psalm-check-type-exact $_l2 = LazyCollection<int, int>&static */
    $_e2 = $e->duplicatesStrict(static fn (M $m): int => $m->foreign_id);
    /** @psalm-check-type-exact $_e2 = Enumerable<int, int>&static */

    // No callback: the items themselves
    $_c3 = $c->duplicates();
    /** @psalm-check-type-exact $_c3 = Collection<int, M>&static */
    $_l3 = $l->duplicates();
    /** @psalm-check-type-exact $_l3 = LazyCollection<int, M>&static */
    $_e3 = $e->duplicates();
    /** @psalm-check-type-exact $_e3 = Enumerable<int, M>&static */

    // A string selector goes through data_get(): unknown value type
    $_c4 = $c->duplicates('foreign_id');
    /** @psalm-check-type-exact $_c4 = Collection<int, mixed>&static */
    $_l4 = $l->duplicates('foreign_id');
    /** @psalm-check-type-exact $_l4 = LazyCollection<int, mixed>&static */
    $_e4 = $e->duplicatesStrict('foreign_id');
    /** @psalm-check-type-exact $_e4 = Enumerable<int, mixed>&static */
}

/**
 * @param Collection<int, M> $c
 * @param LazyCollection<int, M> $l
 * @param Enumerable<int, M> $e
 */
function parity_group_by_key_by(Collection $c, LazyCollection $l, Enumerable $e): void
{
    $_c = $c->groupBy('foreign_id');
    /** @psalm-check-type-exact $_c = Collection<int, Collection<int, M>&static>&static */
    // LazyCollection::groupBy() is passthru() over collect()->groupBy(): the groups are eager Collections
    $_l = $l->groupBy('foreign_id');
    /** @psalm-check-type-exact $_l = LazyCollection<int, Collection<int, M>>&static */
    // CollectionGroupByKeyByHandler does not serve Enumerable receivers (separate task)
    $_e = $e->groupBy('foreign_id');
    /** @psalm-check-type-exact $_e = Enumerable<array-key, Enumerable<int, M>&static>&static */

    $_c2 = $c->groupBy(static fn (M $m): int => $m->foreign_id);
    /** @psalm-check-type-exact $_c2 = Collection<int, Collection<int, M>&static>&static */
    $_l2 = $l->groupBy(static fn (M $m): int => $m->foreign_id);
    /** @psalm-check-type-exact $_l2 = LazyCollection<int, Collection<int, M>>&static */
    $_e2 = $e->groupBy(static fn (M $m): int => $m->foreign_id);
    /** @psalm-check-type-exact $_e2 = Enumerable<int, Enumerable<int, M>&static>&static */

    $_c3 = $c->keyBy('foreign_id');
    /** @psalm-check-type-exact $_c3 = Collection<int, M>&static */
    $_l3 = $l->keyBy('foreign_id');
    /** @psalm-check-type-exact $_l3 = LazyCollection<int, M>&static */
    $_e3 = $e->keyBy('foreign_id');
    /** @psalm-check-type-exact $_e3 = Enumerable<array-key, M>&static */

    $_c4 = $c->keyBy(static fn (M $m): int => $m->foreign_id);
    /** @psalm-check-type-exact $_c4 = Collection<int, M>&static */
    $_l4 = $l->keyBy(static fn (M $m): int => $m->foreign_id);
    /** @psalm-check-type-exact $_l4 = LazyCollection<int, M>&static */
    $_e4 = $e->keyBy(static fn (M $m): int => $m->foreign_id);
    /** @psalm-check-type-exact $_e4 = Enumerable<int, M>&static */
}

/**
 * The handler declines a non-literal column and falls back to the stub's `array-key`.
 *
 * @param Collection<int, M> $c
 * @param LazyCollection<int, M> $l
 * @param non-empty-string $column
 */
function parity_group_by_key_by_decline(Collection $c, LazyCollection $l, string $column): void
{
    $_c = $c->groupBy($column);
    /** @psalm-check-type-exact $_c = Collection<array-key, Collection<int, M>&static>&static */
    $_l = $l->groupBy($column);
    /** @psalm-check-type-exact $_l = LazyCollection<array-key, Collection<int, M>>&static */

    $_c2 = $c->keyBy($column);
    /** @psalm-check-type-exact $_c2 = Collection<array-key, M>&static */
    $_l2 = $l->keyBy($column);
    /** @psalm-check-type-exact $_l2 = LazyCollection<array-key, M>&static */
}

/**
 * @param Collection<int, M> $c
 * @param LazyCollection<int, M> $l
 * @param Enumerable<int, M> $e
 */
function parity_sum_value_count(Collection $c, LazyCollection $l, Enumerable $e): void
{
    // BuilderAggregateHandler narrows the column on Collection; it does not serve Enumerable
    $_c = $c->sum('foreign_id');
    /** @psalm-check-type-exact $_c = int */
    $_l = $l->sum('foreign_id');
    /** @psalm-check-type-exact $_l = int */
    $_e = $e->sum('foreign_id');
    /** @psalm-check-type-exact $_e = float|int */

    $_c2 = $c->sum();
    /** @psalm-check-type-exact $_c2 = float|int */
    $_l2 = $l->sum();
    /** @psalm-check-type-exact $_l2 = float|int */
    $_e2 = $e->sum();
    /** @psalm-check-type-exact $_e2 = float|int */

    $_c3 = $c->sum(static fn (M $m): int => $m->foreign_id);
    /** @psalm-check-type-exact $_c3 = int */
    $_l3 = $l->sum(static fn (M $m): int => $m->foreign_id);
    /** @psalm-check-type-exact $_l3 = int */
    $_e3 = $e->sum(static fn (M $m): int => $m->foreign_id);
    /** @psalm-check-type-exact $_e3 = int */

    // value() is not on the Enumerable interface
    $_c4 = $c->value('foreign_id');
    /** @psalm-check-type-exact $_c4 = mixed|null */
    $_l4 = $l->value('foreign_id');
    /** @psalm-check-type-exact $_l4 = mixed|null */

    $_c5 = $c->count();
    /** @psalm-check-type-exact $_c5 = int<0, max> */
    $_l5 = $l->count();
    /** @psalm-check-type-exact $_l5 = int<0, max> */
    $_e5 = $e->count();
    /** @psalm-check-type-exact $_e5 = int<0, max> */
}

/**
 * @param Collection<int, M> $c
 * @param LazyCollection<int, M> $l
 * @param Enumerable<int, M> $e
 */
function parity_pluck_aggregates(Collection $c, LazyCollection $l, Enumerable $e): void
{
    $_c = $c->pluck('foreign_id');
    /** @psalm-check-type-exact $_c = Collection<int, int> */
    $_l = $l->pluck('foreign_id');
    /** @psalm-check-type-exact $_l = LazyCollection<int, int> */
    // CollectionPluckHandler does not serve Enumerable receivers (separate task)
    $_e = $e->pluck('foreign_id');
    /** @psalm-check-type-exact $_e = Enumerable<array-key, mixed>&static */

    $_c2 = $c->pluck('foreign_id', 'active');
    /** @psalm-check-type-exact $_c2 = Collection<array-key, int> */
    $_l2 = $l->pluck('foreign_id', 'active');
    /** @psalm-check-type-exact $_l2 = LazyCollection<array-key, int> */

    $_c3 = $c->pluck('foreign_id')->all();
    /** @psalm-check-type-exact $_c3 = list<int> */
    $_l3 = $l->pluck('foreign_id')->all();
    /** @psalm-check-type-exact $_l3 = list<int> */

    $_c4 = $c->max('foreign_id');
    /** @psalm-check-type-exact $_c4 = int|null */
    $_l4 = $l->max('foreign_id');
    /** @psalm-check-type-exact $_l4 = int|null */
    $_c5 = $c->min('foreign_id');
    /** @psalm-check-type-exact $_c5 = int|null */
    $_l5 = $l->min('foreign_id');
    /** @psalm-check-type-exact $_l5 = int|null */
    $_c6 = $c->avg('foreign_id');
    /** @psalm-check-type-exact $_c6 = float|int|null */
    $_l6 = $l->avg('foreign_id');
    /** @psalm-check-type-exact $_l6 = float|int|null */
}

/**
 * The handlers decline a non-model value or an unknown column; the stub types stay identical.
 *
 * @param Collection<int, array{id: int}> $c
 * @param LazyCollection<int, array{id: int}> $l
 * @param Collection<int, M> $cm
 * @param LazyCollection<int, M> $lm
 * @param non-empty-string $column
 */
function parity_pluck_aggregates_decline(Collection $c, LazyCollection $l, Collection $cm, LazyCollection $lm, string $column): void
{
    $_c = $c->pluck('id');
    /** @psalm-check-type-exact $_c = Collection<array-key, mixed>&static */
    $_l = $l->pluck('id');
    /** @psalm-check-type-exact $_l = LazyCollection<array-key, mixed>&static */

    $_c2 = $cm->pluck('unknown_column');
    /** @psalm-check-type-exact $_c2 = Collection<int, mixed> */
    $_l2 = $lm->pluck('unknown_column');
    /** @psalm-check-type-exact $_l2 = LazyCollection<int, mixed> */

    $_c3 = $c->max('id');
    /** @psalm-check-type-exact $_c3 = mixed */
    $_l3 = $l->max('id');
    /** @psalm-check-type-exact $_l3 = mixed */

    $_c4 = $cm->max($column);
    /** @psalm-check-type-exact $_c4 = mixed */
    $_l4 = $lm->max($column);
    /** @psalm-check-type-exact $_l4 = mixed */
}

function parity_cursor_vs_get(): void
{
    $_cursor = M::query()->cursor()->pluck('foreign_id');
    /** @psalm-check-type-exact $_cursor = LazyCollection<int, int> */
    $_get = M::query()->get()->pluck('foreign_id');
    /** @psalm-check-type-exact $_get = Collection<int, int> */
}
/**
 * Every value atomic must be the same proven model; a model mixed with a plain array declines.
 *
 * @param LazyCollection<int, M|array{foreign_id: float}> $l
 * @param Collection<int, M|array{foreign_id: float}> $c
 * @param LazyCollection<int, M>|LazyCollection<int, array{foreign_id: float}> $lu
 * @param Collection<int, M>|Collection<int, array{foreign_id: float}> $cu
 */
function model_and_non_model_values_decline(LazyCollection $l, Collection $c, $lu, $cu): void
{
    $_lp = $l->pluck('foreign_id');
    /** @psalm-check-type-exact $_lp = LazyCollection<array-key, mixed>&static */
    $_cp = $c->pluck('foreign_id');
    /** @psalm-check-type-exact $_cp = Collection<array-key, mixed>&static */
    $_ls = $l->sum('foreign_id');
    /** @psalm-check-type-exact $_ls = float|int */
    $_cs = $c->sum('foreign_id');
    /** @psalm-check-type-exact $_cs = float|int */
    $_lm = $l->max('foreign_id');
    /** @psalm-check-type-exact $_lm = mixed */
    $_cm = $c->max('foreign_id');
    /** @psalm-check-type-exact $_cm = mixed */

    // Outer union receiver: the float branch must survive
    $_lup = $lu->pluck('foreign_id');
    /** @psalm-check-type-exact $_lup = LazyCollection<array-key, mixed>&static */
    $_cup = $cu->pluck('foreign_id');
    /** @psalm-check-type-exact $_cup = Collection<array-key, mixed>&static */
}

/**
 * LazyCollection::keyBy() yields the resolved key straight from a generator, so a bool column keeps
 * bool keys (Collection coerces them to int through its array).
 *
 * @param Collection<int, M> $c
 * @param LazyCollection<int, M> $l
 */
function lazy_key_by_bool_column_declines(Collection $c, LazyCollection $l): void
{
    $_c = $c->keyBy('active');
    /** @psalm-check-type-exact $_c = Collection<int, M>&static */
    $_l = $l->keyBy('active');
    /** @psalm-check-type-exact $_l = LazyCollection<array-key, M>&static */
    // groupBy() goes through collect(), which coerces bool group keys to int
    $_lg = $l->groupBy('active');
    /** @psalm-check-type-exact $_lg = LazyCollection<int, Collection<int, M>>&static */
}
--EXPECTF--
