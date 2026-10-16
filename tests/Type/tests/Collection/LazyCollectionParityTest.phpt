--FILE--
<?php declare(strict_types=1);

/**
 * LazyCollection must type every method the same as Collection does (Collection<int, M> vs
 * LazyCollection<int, M>, only the class name differs). Each function runs one call on
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
    $_c = $c->duplicates(static fn (M $m): int => $m->foreign_id);
    /** @psalm-check-type-exact $_c = Collection<int, M>&static */
    $_l = $l->duplicates(static fn (M $m): int => $m->foreign_id);
    /** @psalm-check-type-exact $_l = LazyCollection<int, M>&static */
    $_e = $e->duplicates(static fn (M $m): int => $m->foreign_id);
    /** @psalm-check-type-exact $_e = Enumerable<int, M>&static */

    $_c2 = $c->duplicatesStrict(static fn (M $m): int => $m->foreign_id);
    /** @psalm-check-type-exact $_c2 = Collection<int, M>&static */
    $_l2 = $l->duplicatesStrict(static fn (M $m): int => $m->foreign_id);
    /** @psalm-check-type-exact $_l2 = LazyCollection<int, M>&static */
    $_e2 = $e->duplicatesStrict(static fn (M $m): int => $m->foreign_id);
    /** @psalm-check-type-exact $_e2 = Enumerable<int, M>&static */
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
    $_l = $l->groupBy('foreign_id');
    /** @psalm-check-type-exact $_l = LazyCollection<int, LazyCollection<int, M>&static>&static */
    // CollectionGroupByKeyByHandler does not serve Enumerable receivers (separate task)
    $_e = $e->groupBy('foreign_id');
    /** @psalm-check-type-exact $_e = Enumerable<array-key, Enumerable<int, M>&static>&static */

    $_c2 = $c->groupBy(static fn (M $m): int => $m->foreign_id);
    /** @psalm-check-type-exact $_c2 = Collection<int, Collection<int, M>&static>&static */
    $_l2 = $l->groupBy(static fn (M $m): int => $m->foreign_id);
    /** @psalm-check-type-exact $_l2 = LazyCollection<int, LazyCollection<int, M>&static>&static */
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
    /** @psalm-check-type-exact $_l = LazyCollection<array-key, LazyCollection<int, M>&static>&static */

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
--EXPECTF--
