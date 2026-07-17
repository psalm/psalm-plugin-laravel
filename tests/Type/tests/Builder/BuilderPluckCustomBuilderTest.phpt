--FILE--
<?php declare(strict_types=1);

use App\Builders\InvoiceDeepBuilder;
use App\Builders\VehicleBuilder;
use App\Collections\InvoiceCollection;
use App\Models\Invoice;
use Illuminate\Support\Collection;

/**
 * pluck() narrowing on custom Builder subclasses that are not themselves generic.
 *
 * Builder::pluck() infers the model from `$event->getTemplateTypeParameters()`, which is
 * empty for a non-generic custom builder (e.g. `final class InvoiceBuilder extends
 * Builder<Invoice>` — no own @template), and from the LHS type's TGenericObject
 * type_params, which a plain TNamedObject LHS also lacks. ModelPropertyResolver now
 * falls back to the classlike storage's `@extends Builder<TModel>` binding
 * (`template_extended_params[Builder::class]['TModel']`) for this case.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1287
 */

// --- Direct, non-generic custom Builder subclass ---

/**
 * Invoice::query() returns InvoiceBuilder (`final class InvoiceBuilder extends
 * Builder<Invoice>`, wired via #[UseEloquentBuilder] — the "standard pattern" the issue
 * describes). Both value and key narrow from Invoice's @property annotations.
 */
function test_pluck_value_and_key_on_direct_custom_builder(): void
{
    $_result = Invoice::query()->pluck('invoice_number', 'status');
    /** @psalm-check-type-exact $_result = Collection<string, string> */
}

/** Value-only pluck on the same non-generic custom builder: key stays int. */
function test_pluck_value_only_on_direct_custom_builder(): void
{
    $_result = Invoice::query()->pluck('invoice_number');
    /** @psalm-check-type-exact $_result = Collection<int, string> */
}

/** Unknown column on a non-generic custom builder falls back to the stub type, no crash. */
function test_pluck_unknown_column_on_direct_custom_builder(): void
{
    $_result = Invoice::query()->pluck('unknown_column');
    /** @psalm-check-type-exact $_result = Collection<array-key, mixed> */
}

// --- Deeper inheritance chain ---

/**
 * InvoiceDeepBuilder extends AbstractPluckableBuilder<Invoice> extends Builder<TModel> —
 * TModel must resolve through the flattened ancestor chain, not just a direct parent.
 */
function test_pluck_on_deep_custom_builder_chain(InvoiceDeepBuilder $builder): void
{
    $_result = $builder->pluck('invoice_number', 'status');
    /** @psalm-check-type-exact $_result = Collection<string, string> */
}

// --- Generic custom builder used with a concrete instantiation (regression guard) ---

/**
 * VehicleBuilder is itself generic (`@template TModel of Model`). Concretely
 * instantiated as VehicleBuilder<Vehicle>, the LHS type is a TGenericObject and was
 * already resolved by the existing type_params fallback before this fix — must keep
 * working unchanged.
 *
 * @param VehicleBuilder<\App\Models\Vehicle> $builder
 */
function test_pluck_on_generic_custom_builder_instance_unaffected(VehicleBuilder $builder): void
{
    $_result = $builder->pluck('make', 'model');
    /** @psalm-check-type-exact $_result = Collection<string, string> */
}

// --- Non-Builder custom subclass (must not be affected by the new fallback) ---

/**
 * InvoiceCollection is a non-generic custom Collection subclass — the Collection
 * analogue of InvoiceBuilder's shape (`@extends Collection<int, Invoice>`, no own
 * @template). Collection does not extend Builder, so the new
 * template_extended_params[Builder::class] fallback must never fire for it; narrowing
 * such Collection subclasses is a separate, out-of-scope gap this fix does not touch.
 */
function test_pluck_on_non_builder_custom_collection_unaffected(InvoiceCollection $collection): void
{
    $_result = $collection->pluck('invoice_number');
    /** @psalm-check-type-exact $_result = Collection<array-key, mixed> */
}
?>
--EXPECTF--
