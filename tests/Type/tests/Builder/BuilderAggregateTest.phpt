--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Part;

/**
 * Builder::sum/avg/average/min/max should narrow the return type using the
 * column's resolved Psalm type (user @property → cast → schema). The stub
 * declares `numeric-string|int|float[|null]` for unknown columns; once we
 * know the column is int / float / Carbon / etc, the operand types stop
 * triggering InvalidOperand at every call site.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1004
 */

// --- sum() ---

/** Customer has `@property int<0, max> $vehicles_count` → sum narrows to int. */
function test_sum_on_int_column(): void
{
    $_result = Customer::query()->sum('vehicles_count');
    /** @psalm-check-type-exact $_result = int */
}

/** Issue reproducer: summing across a chain in strict-operand mode. */
function test_sum_chain_strict_operand(): void
{
    $_result = 100 + Customer::query()->where('active', true)->sum('vehicles_count');
    /** @psalm-check-type-exact $_result = int */
}

/** Part has `@property float $unit_price` → sum narrows to float. */
function test_sum_on_float_column(): void
{
    $_result = Part::query()->sum('unit_price');
    /** @psalm-check-type-exact $_result = float */
}

/** Unknown column falls back to the stub's declared return. */
function test_sum_on_unknown_column(): void
{
    $_result = Customer::query()->sum('unknown_column');
    /** @psalm-check-type-exact $_result = float|int|numeric-string */
}

/** Dynamic column name → no literal → fall back to stub. */
function test_sum_with_dynamic_column(string $column): void
{
    $_result = Customer::query()->sum($column);
    /** @psalm-check-type-exact $_result = float|int|numeric-string */
}

// --- avg() / average() ---

/** Numeric column → float|int|null (Laravel returns null on empty table). */
function test_avg_on_int_column(): void
{
    $_result = Customer::query()->avg('vehicles_count');
    /** @psalm-check-type-exact $_result = float|int|null */
}

/** average() is an alias for avg(). */
function test_average_on_float_column(): void
{
    $_result = Part::query()->average('unit_price');
    /** @psalm-check-type-exact $_result = float|int|null */
}

/** Non-numeric column (string id) → defer to stub. */
function test_avg_on_non_numeric_column_defers(): void
{
    $_result = Customer::query()->avg('id');
    /** @psalm-check-type-exact $_result = float|int|null|numeric-string */
}

// --- min() / max() ---

/** Numeric column → column type | null (empty-table case). */
function test_min_on_int_column(): void
{
    $_result = Customer::query()->min('vehicles_count');
    /** @psalm-check-type-exact $_result = int<0, max>|null */
}

/** String column. */
function test_max_on_string_column(): void
{
    $_result = Customer::query()->max('id');
    /** @psalm-check-type-exact $_result = string|null */
}

/**
 * Nullable column already includes null in @property — adding null is
 * idempotent.
 */
function test_min_on_nullable_carbon_column(): void
{
    $_result = Customer::query()->min('email_verified_at');
    /** @psalm-check-type-exact $_result = \Carbon\CarbonInterface|null */
}

/**
 * Custom-cast / Carbon columns at the call site of a date aggregate produce
 * the cast type plus null — matches the user-facing `@property` view, even
 * though Laravel's query-level aggregate does not apply casts at runtime.
 * Documented trade-off in BuilderAggregateHandler.
 */
function test_max_on_nullable_carbon_column(): void
{
    $_result = Customer::query()->max('email_verified_at');
    /** @psalm-check-type-exact $_result = \Carbon\CarbonInterface|null */
}

// --- relation / static-call passthrough (matches BuilderPluckHandler coverage) ---

/** Static call on model proxies through __callStatic to Builder<Customer>::sum(). */
function test_sum_on_model_static(): void
{
    $_result = Customer::sum('vehicles_count');
    /** @psalm-check-type-exact $_result = int */
}

/**
 * Aggregate after a where() preserves the model template through the chain.
 */
function test_max_after_where(): void
{
    $_result = Customer::query()->where('active', true)->max('id');
    /** @psalm-check-type-exact $_result = string|null */
}

/** Direct query on Vehicle (Vehicle has `@property string $make`). */
function test_max_on_vehicle_query(): void
{
    $_result = \App\Models\Vehicle::query()->max('make');
    /** @psalm-check-type-exact $_result = string|null */
}
?>
--EXPECTF--
