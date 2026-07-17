--FILE--
<?php declare(strict_types=1);

use App\Builders\InvoiceBuilder;
use App\Builders\InvoiceDeepBuilder;
use App\Builders\VehicleBuilder;
use App\Collections\InvoiceCollection;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Builder;
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

/**
 * Unknown column on a non-generic custom builder: value falls back to mixed, key
 * stays int (no $key argument) — see issue #1286. No crash either way.
 */
function test_pluck_unknown_column_on_direct_custom_builder(): void
{
    $_result = Invoice::query()->pluck('unknown_column');
    /** @psalm-check-type-exact $_result = Collection<int, mixed> */
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

// --- Union LHS over the same model (lock-in) ---

/**
 * Receiver typed as a union of two Builder shapes over the SAME model: InvoiceBuilder
 * (the non-generic #1287 fallback) and the plain generic Builder<Invoice> (the
 * pre-existing template_params path). Locks in that narrowing still applies when the
 * static type isn't a single atomic — each union member independently resolves to
 * Invoice, so the value narrows from Invoice's `@property string $invoice_number`.
 *
 * @param InvoiceBuilder|Builder<Invoice> $builder
 */
function test_pluck_on_union_of_builder_types_over_same_model($builder): void
{
    $_result = $builder->pluck('invoice_number');
    /** @psalm-check-type-exact $_result = Collection<int, string> */
}

// --- Generic builder's own unsubstituted template (lock-in) ---

/**
 * Locks in the safe-null path for a generic custom builder's OWN TModel while it is
 * still unsubstituted — the shape a method declared inside such a builder's class body
 * sees via `$this` (e.g. a hypothetical method on VehicleBuilder calling
 * `$this->pluck('id')`).
 *
 * We deliberately do NOT add that method to App\Builders\VehicleBuilder and assert on
 * it there: psalm-tester passes this .phpt as a bare file argument, so referenced
 * classes are reflected but their method bodies are never analyzed by this suite (see
 * tests/Type/README.md gotcha #4 and ArtistBuilder.php's docblock for the same
 * limitation) — a `@psalm-check-type-exact` embedded in a fixture's method body would
 * silently assert nothing. Reproducing the identical LHS shape from an analyzed
 * function — VehicleBuilder<TModel> with TModel a template LOCAL to this function,
 * standing in for VehicleBuilder's own unresolved template — exercises the exact same
 * path in extractModelFromLhsBuilderExtends(): it fetches VehicleBuilder's own class
 * storage, finds template_extended_params[Builder::class]['TModel'] bound to
 * VehicleBuilder's own (unsubstituted) TModel, and extractModelFromUnion() correctly
 * yields null for it since a TTemplateParam is never a resolved Model. No crash;
 * pluck() falls back to its stub type.
 *
 * @template TModel of \Illuminate\Database\Eloquent\Model
 * @param VehicleBuilder<TModel> $builder
 */
function test_pluck_with_unsubstituted_generic_builder_template($builder): void
{
    $_result = $builder->pluck('id');
    /** @psalm-check-type-exact $_result = Collection<array-key, mixed> */
}
?>
--EXPECTF--
