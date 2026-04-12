--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Support\Collection;

/**
 * pluck() on Builder and Collection should infer value type from model @property annotations.
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/486
 */

// --- Builder::pluck() ---

/** Customer has @property string $id — pluck should return Collection<int, string> */
function test_pluck_with_known_property(): void
{
    $_result = Customer::query()->pluck('id');
    /** @psalm-check-type-exact $_result = Collection<int, string> */
}

/** Customer has @property CarbonInterface|null $email_verified_at */
function test_pluck_with_nullable_property(): void
{
    $_result = Customer::query()->pluck('email_verified_at');
    /** @psalm-check-type-exact $_result = Collection<int, \Carbon\CarbonInterface|null> */
}

/** When the column name is not a known @property, fall back to default behavior */
function test_pluck_with_unknown_column(): void
{
    $_result = Customer::query()->pluck('unknown_column');
    /** @psalm-check-type-exact $_result = Collection<array-key, mixed> */
}

/** When the column is a variable (not a string literal), fall back to default */
function test_pluck_with_dynamic_column(string $column): void
{
    $_result = Customer::query()->pluck($column);
    /** @psalm-check-type-exact $_result = Collection<array-key, mixed> */
}

/**
 * Key column uses array-key because Laravel does NOT apply casts to key columns —
 * keys come from raw PDO results and are always string|int.
 */
function test_pluck_with_key_column(): void
{
    $_result = Customer::query()->pluck('email_verified_at', 'id');
    /** @psalm-check-type-exact $_result = Collection<array-key, \Carbon\CarbonInterface|null> */
}

/** pluck with an unknown key column should still use array-key for keys */
function test_pluck_with_unknown_key_column(): void
{
    $_result = Customer::query()->pluck('id', 'unknown_key');
    /** @psalm-check-type-exact $_result = Collection<array-key, string> */
}

/** Variable key column should still use array-key (not int) */
function test_pluck_with_dynamic_key_column(string $keyColumn): void
{
    $_result = Customer::query()->pluck('id', $keyColumn);
    /** @psalm-check-type-exact $_result = Collection<array-key, string> */
}

/** Template type should be preserved through chained Builder methods */
function test_pluck_after_where(): void
{
    $_result = Customer::query()->where('active', true)->pluck('id');
    /** @psalm-check-type-exact $_result = Collection<int, string> */
}

// --- Model static call (proxied via ModelMethodHandler) ---

/** Customer::pluck() goes through __callStatic -> Builder<Customer>->pluck() */
function test_pluck_on_model_static(): void
{
    $_result = Customer::pluck('id');
    /** @psalm-check-type-exact $_result = Collection<int, string> */
}

// --- Collection::pluck() ---

/** pluck on Eloquent Collection infers value type from model @property */
function test_pluck_on_eloquent_collection(): void
{
    $customers = Customer::all();
    $_result = $customers->pluck('id');
    /** @psalm-check-type-exact $_result = Collection<int, string> */
}

/** pluck on Collection with key argument should use array-key */
function test_pluck_on_collection_with_key(): void
{
    $customers = Customer::all();
    $_result = $customers->pluck('email_verified_at', 'id');
    /** @psalm-check-type-exact $_result = Collection<array-key, \Carbon\CarbonInterface|null> */
}

/** pluck on Collection with unknown column falls back to default */
function test_pluck_on_collection_unknown_column(): void
{
    $customers = Customer::all();
    $_result = $customers->pluck('unknown_column');
    /** @psalm-check-type-exact $_result = Collection<array-key, mixed> */
}

/** Full query pipeline: query()->get()->pluck() preserves template types */
function test_pluck_on_get_result(): void
{
    $_result = Customer::query()->get()->pluck('id');
    /** @psalm-check-type-exact $_result = Collection<int, string> */
}
?>
--EXPECTF--
