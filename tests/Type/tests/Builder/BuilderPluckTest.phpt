--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Support\Collection;

/**
 * pluck() on Builder, Collection, and Relation should infer value type from model
 * @property annotations, and narrow the key type when an array-key compatible
 * @property exists for the key column.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/486
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/967
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

/**
 * When the column name is not a known @property, the value falls back to mixed. No
 * $key argument means Laravel's positional pluck() still yields sequential int keys.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1286
 */
function test_pluck_with_unknown_column(): void
{
    $_result = Customer::query()->pluck('unknown_column');
    /** @psalm-check-type-exact $_result = Collection<int, mixed> */
}

/** When the column is a variable (not a string literal), fall back to default */
function test_pluck_with_dynamic_column(string $column): void
{
    $_result = Customer::query()->pluck($column);
    /** @psalm-check-type-exact $_result = Collection<array-key, mixed> */
}

/**
 * Key column narrows to the @property type when that type is a subset of array-key
 * (int|string). Customer has `@property string $id`, so TKey narrows to string.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/967
 */
function test_pluck_with_array_key_compatible_key_column(): void
{
    $_result = Customer::query()->pluck('email_verified_at', 'id');
    /** @psalm-check-type-exact $_result = Collection<string, \Carbon\CarbonInterface|null> */
}

/**
 * Customer has `@property int<0, max> $vehicles_count` — TKey narrows to int<0, max>.
 */
function test_pluck_with_int_range_key_column(): void
{
    $_result = Customer::query()->pluck('id', 'vehicles_count');
    /** @psalm-check-type-exact $_result = Collection<int<0, max>, string> */
}

/**
 * Key column @property is CarbonInterface|null, not a subset of array-key. The key
 * falls back to array-key instead of producing an invalid TKey for Collection.
 */
function test_pluck_with_non_array_key_compatible_key_column(): void
{
    $_result = Customer::query()->pluck('id', 'email_verified_at');
    /** @psalm-check-type-exact $_result = Collection<array-key, string> */
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

/** pluck on Eloquent Collection narrows TKey when key column @property is array-key compatible */
function test_pluck_on_collection_with_key(): void
{
    $customers = Customer::all();
    $_result = $customers->pluck('email_verified_at', 'id');
    /** @psalm-check-type-exact $_result = Collection<string, \Carbon\CarbonInterface|null> */
}

/**
 * pluck on Collection with an unknown column: value falls back to mixed, key stays
 * int (no $key argument). Mirrors the Builder-side behavior — see issue #1286.
 */
function test_pluck_on_collection_unknown_column(): void
{
    $customers = Customer::all();
    $_result = $customers->pluck('unknown_column');
    /** @psalm-check-type-exact $_result = Collection<int, mixed> */
}

/** Full query pipeline: query()->get()->pluck() preserves template types */
function test_pluck_on_get_result(): void
{
    $_result = Customer::query()->get()->pluck('id');
    /** @psalm-check-type-exact $_result = Collection<int, string> */
}

// --- Relation::pluck() ---

/**
 * pluck() chained off a HasMany relation. Vehicle has `@property string $make` and
 * `@property string $model`, so the result narrows on both axes.
 *
 * This is the original repro from #967.
 */
function test_pluck_on_has_many_relation_with_both_args(Customer $customer): void
{
    $_result = $customer->vehicles()->pluck('make', 'model');
    /** @psalm-check-type-exact $_result = Collection<string, string> */
}

/** Single-argument pluck on a HasMany relation narrows value, key stays int. */
function test_pluck_on_has_many_relation_single_arg(Customer $customer): void
{
    $_result = $customer->vehicles()->pluck('make');
    /** @psalm-check-type-exact $_result = Collection<int, string> */
}

/** ->all() on the narrowed Collection produces array<string, string> — the issue's expected shape. */
function test_pluck_all_on_has_many_relation(Customer $customer): void
{
    $_result = $customer->vehicles()->pluck('make', 'model')->all();
    /** @psalm-check-type-exact $_result = array<string, string> */
}

/**
 * @param \Illuminate\Database\Eloquent\Relations\BelongsTo<Customer, Vehicle> $belongsTo
 */
function test_pluck_on_belongs_to_relation($belongsTo): void
{
    $_result = $belongsTo->pluck('id');
    /** @psalm-check-type-exact $_result = Collection<int, string> */
}

/**
 * Two-argument pluck on BelongsTo. Customer has `@property string $id` and
 * `@property non-empty-string $first_name_using_legacy_accessor` — both array-key
 * compatible, so TKey narrows.
 *
 * @param \Illuminate\Database\Eloquent\Relations\BelongsTo<Customer, Vehicle> $belongsTo
 */
function test_pluck_on_belongs_to_relation_with_key($belongsTo): void
{
    $_result = $belongsTo->pluck('first_name_using_legacy_accessor', 'id');
    /** @psalm-check-type-exact $_result = Collection<string, non-empty-string> */
}

/**
 * Two-argument pluck on HasOne. Confirms the LHS-type fallback works for relation
 * subclasses beyond HasMany/BelongsTo.
 */
function test_pluck_on_has_one_relation_with_key(Customer $customer): void
{
    $_result = $customer->primaryVehicle()->pluck('make', 'model');
    /** @psalm-check-type-exact $_result = Collection<string, string> */
}

/**
 * Dynamic key column on a relation: keys must fall back to array-key, not get
 * accidentally narrowed by the LHS-type fallback.
 */
function test_pluck_on_has_many_relation_dynamic_key(Customer $customer, string $keyColumn): void
{
    $_result = $customer->vehicles()->pluck('make', $keyColumn);
    /** @psalm-check-type-exact $_result = Collection<array-key, string> */
}
?>
--EXPECTF--
