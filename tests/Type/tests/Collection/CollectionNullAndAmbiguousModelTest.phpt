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
use App\Models\Invoice;
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
 * MorphTo<Customer|Supplier, Invoice>: the related slot is ambiguous, so the aggregate declines
 * instead of reading the column off the declaring Invoice (the next slot).
 */
function ambiguous_morph_to_declines(Invoice $invoice): void
{
    $_min = $invoice->billable()->min('total_amount');
    /** @psalm-check-type-exact $_min = mixed */
    $_sum = $invoice->billable()->sum('total_amount');
    /** @psalm-check-type-exact $_sum = float|int|numeric-string */
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
--EXPECTF--
