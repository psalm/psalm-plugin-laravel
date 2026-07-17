--FILE--
<?php declare(strict_types=1);

use App\Builders\InvoiceDeepBuilder;
use App\Models\Customer;
use App\Models\Invoice;

/**
 * sum()/min()/max() narrowing on custom Builder subclasses that are not themselves
 * generic — the aggregate-side analogue of #1287 (fixed for pluck()).
 *
 * BuilderAggregateHandler::resolveModelClass() had the identical gap
 * BuilderPluckHandler had before #1287: the event's template parameters are empty
 * for a non-generic custom builder (no own @template), and the LHS fallback only
 * inspected TGenericObject atomics, missing the plain TNamedObject a non-generic
 * custom builder's LHS type actually is.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1294
 */

// --- Direct, non-generic custom Builder subclass ---

/**
 * Invoice::query() returns InvoiceBuilder (`final class InvoiceBuilder extends
 * Builder<Invoice>`, wired via #[UseEloquentBuilder]). total_amount is
 * `@property float`.
 */
function test_sum_on_direct_custom_builder(): void
{
    $_result = Invoice::query()->sum('total_amount');
    /** @psalm-check-type-exact $_result = float */
}

/** min()/max() add null for the empty-table case, same as on a plain Builder<Model>. */
function test_min_on_direct_custom_builder(): void
{
    $_result = Invoice::query()->min('total_amount');
    /** @psalm-check-type-exact $_result = float|null */
}

function test_max_on_direct_custom_builder(): void
{
    $_result = Invoice::query()->max('total_amount');
    /** @psalm-check-type-exact $_result = float|null */
}

/** Unknown column on a non-generic custom builder falls back to the stub type, no crash. */
function test_sum_unknown_column_on_direct_custom_builder(): void
{
    $_result = Invoice::query()->sum('unknown_column');
    /** @psalm-check-type-exact $_result = float|int|numeric-string */
}

// --- Deeper inheritance chain ---

/**
 * InvoiceDeepBuilder extends AbstractPluckableBuilder<Invoice> extends Builder<TModel>
 * (the same #1287 fixture reused from BuilderPluckCustomBuilderTest.phpt) — TModel
 * must resolve through the flattened ancestor chain, not just a direct parent.
 */
function test_sum_on_deep_custom_builder_chain(InvoiceDeepBuilder $builder): void
{
    $_result = $builder->sum('total_amount');
    /** @psalm-check-type-exact $_result = float */
}

// --- Regression guard: plain Builder<Model> aggregate unaffected ---

/**
 * Customer is queried through a plain Builder<Customer> (no custom builder) — the
 * event's template parameters already resolve the model, so the new fallback must
 * never be reached and behavior stays exactly as covered by BuilderAggregateTest.phpt.
 */
function test_sum_on_plain_builder_unaffected(): void
{
    $_result = Customer::query()->sum('vehicles_count');
    /** @psalm-check-type-exact $_result = int */
}
?>
--EXPECTF--
