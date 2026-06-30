--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Shop;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Aggregates (sum/avg/average/min/max) must narrow to the aggregated column's
 * resolved type for the relation-level and in-memory-collection call shapes, not
 * only the direct query builder. Before #1182 both returned `mixed` (relations
 * forward via __call; Collection::sum(string) was unnarrowed), producing
 * MixedOperand the moment the result was used arithmetically.
 *
 * Supplier::parts() is HasMany<Part>; Part has `@property float $unit_price`.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1182
 */

// --- Relation-level aggregate (issue case 1) ---

/** HasMany sum → float. */
function rel_sum(Supplier $s): void
{
    $_r = $s->parts()->sum('unit_price');
    /** @psalm-check-type-exact $_r = float */
}

/** HasMany avg → float|int|null (null on empty). */
function rel_avg(Supplier $s): void
{
    $_r = $s->parts()->avg('unit_price');
    /** @psalm-check-type-exact $_r = float|int|null */
}

/** average() is an alias for avg(). */
function rel_average(Supplier $s): void
{
    $_r = $s->parts()->average('unit_price');
    /** @psalm-check-type-exact $_r = float|int|null */
}

/** HasMany min → column type | null. */
function rel_min(Supplier $s): void
{
    $_r = $s->parts()->min('unit_price');
    /** @psalm-check-type-exact $_r = float|null */
}

/** HasMany max → column type | null. */
function rel_max(Supplier $s): void
{
    $_r = $s->parts()->max('unit_price');
    /** @psalm-check-type-exact $_r = float|null */
}

/**
 * BelongsToMany<Part, Shop, Pivot, 'pivot'> — four template params, three of them
 * models (related, declaring, pivot). The aggregated model must be the *related*
 * model (param 0), never the declaring model or the pivot.
 */
function rel_belongs_to_many_picks_related(Shop $shop): void
{
    $_r = $shop->suggestedParts()->sum('unit_price');
    /** @psalm-check-type-exact $_r = float */
}

/** Aggregate after a chained where() (forwarding self-return path) still narrows. */
function rel_chained(Supplier $s): void
{
    $_r = $s->parts()->where('unit_price', '>', 1)->sum('unit_price');
    /** @psalm-check-type-exact $_r = float */
}

/** Unknown column falls back to the Relation stub's declared return (not mixed). */
function rel_unknown_column(Supplier $s): void
{
    $_r = $s->parts()->sum('does_not_exist');
    /** @psalm-check-type-exact $_r = float|int|numeric-string */
}

/** Issue's actual symptom: arithmetic on the narrowed result is no longer MixedOperand. */
function rel_arithmetic_no_mixed_operand(Supplier $s): float
{
    return $s->parts()->sum('unit_price') / 100.0;
}

// --- In-memory collection aggregate (issue case 2) ---
//
// Covers Illuminate\Support\Collection and Illuminate\Database\Eloquent\Collection.
// Custom collection subclasses (newCollection() / #[CollectedBy]) are not narrowed on
// the in-memory read — they resolve to `mixed`, so the original MixedOperand persists
// for `$model->customCollectionRelation->sum('col')`. The relation-level path above
// ($model->rel()->sum('col')) still narrows those models.

/** Eloquent\Collection<int, Part> sum → float. */
function mem_eloquent_collection_sum(EloquentCollection $parts): void
{
    /** @var EloquentCollection<int, \App\Models\Part> $parts */
    $_r = $parts->sum('unit_price');
    /** @psalm-check-type-exact $_r = float */
}

/** $model->relation read backed by the default Eloquent\Collection narrows on min (string column). */
function mem_relation_read_min(Customer $c): void
{
    $_r = $c->vehicles->min('make');
    /** @psalm-check-type-exact $_r = string|null */
}

/** Support\Collection<int, Part> avg → float|int|null. */
function mem_support_collection_avg(SupportCollection $parts): void
{
    /** @var SupportCollection<int, \App\Models\Part> $parts */
    $_r = $parts->avg('unit_price');
    /** @psalm-check-type-exact $_r = float|int|null */
}
?>
--EXPECTF--
