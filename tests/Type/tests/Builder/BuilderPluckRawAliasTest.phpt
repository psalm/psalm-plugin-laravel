--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Support\Collection;

/**
 * pluck($value, $key) must not discard achievable key narrowing when the value column
 * is a raw select alias or otherwise not a @property (e.g.
 * `selectRaw('COUNT(*) AS cnt')->groupBy(...)->pluck('cnt', 'id')`). The value type
 * falls back to mixed instead of bailing the whole result to null, since the value
 * and key axes are resolved independently.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1286
 */

/**
 * Raw select alias for the value column, known @property for the key column: key
 * narrows to Customer's `@property string $id`, value stays mixed.
 */
function test_pluck_raw_alias_value_with_known_key(): void
{
    $_result = Customer::query()
        ->selectRaw('id, COUNT(*) AS raw_count')
        ->groupBy('id')
        ->pluck('raw_count', 'id');
    /** @psalm-check-type-exact $_result = Collection<string, mixed> */
}

/**
 * Raw select alias with no $key argument: Laravel's positional pluck() always yields
 * sequential int keys, so this is still strictly more precise than the stub default,
 * even though the value column can't be narrowed.
 */
function test_pluck_raw_alias_value_without_key(): void
{
    $_result = Customer::query()->pluck('raw_count');
    /** @psalm-check-type-exact $_result = Collection<int, mixed> */
}

/**
 * Both the value and the key columns are unresolvable: neither axis narrows past the
 * stub's default `Collection<array-key, mixed>`, so the handler defers to it instead
 * of constructing an identical type.
 */
function test_pluck_raw_alias_value_with_unknown_key(): void
{
    $_result = Customer::query()->pluck('raw_count', 'unknown_key');
    /** @psalm-check-type-exact $_result = Collection<array-key, mixed> */
}

/**
 * Combined case cited by both #1286 and #1287: a raw select alias on a non-generic
 * custom Builder subclass (InvoiceBuilder, resolved via the #1287 fix to
 * template_extended_params[Builder::class]) whose key column IS a @property. This is
 * the real-world aggregate-pluck shape both issues describe.
 */
function test_pluck_raw_alias_value_on_custom_builder_with_known_key(): void
{
    $_result = Invoice::query()
        ->selectRaw('status, COUNT(*) AS raw_count')
        ->groupBy('status')
        ->pluck('raw_count', 'status');
    /** @psalm-check-type-exact $_result = Collection<string, mixed> */
}

/**
 * CollectionPluckHandler shares resolvePluckReturnType() with BuilderPluckHandler —
 * confirm the same mixed-value fallback and key narrowing apply on an in-memory
 * Collection, not just a Builder.
 */
function test_pluck_raw_alias_value_on_collection_with_known_key(): void
{
    $customers = Customer::all();
    $_result = $customers->pluck('raw_count', 'id');
    /** @psalm-check-type-exact $_result = Collection<string, mixed> */
}
?>
--EXPECTF--
