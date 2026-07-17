--FILE--
<?php declare(strict_types=1);

use App\Builders\InvoiceBuilder;
use App\Collections\InvoiceCollection;
use App\Models\Invoice;
use Illuminate\Support\Collection;

/**
 * pluck() narrowing on custom Collection subclasses that are not themselves generic —
 * the Collection-side mirror image of #1287 (fixed for Builder).
 *
 * CollectionPluckHandler shares ModelPropertyResolver::resolvePluckReturnType() with
 * BuilderPluckHandler ($modelTemplateIndex 1 instead of 0). A non-generic custom
 * Collection subclass (`final class InvoiceCollection extends Collection<int, Invoice>
 * {}`, no own @template) has the same shape #1287 diagnosed for Builder: the event's
 * template parameters are empty, and the LHS type is a plain TNamedObject rather than
 * a TGenericObject, so neither existing fallback matches. #1287's own Builder-scoped
 * fallback (template_extended_params[Builder::class]) correctly doesn't match either,
 * since Collection never extends Builder — this needed its own, separately-scoped
 * fallback keyed on the Collection hierarchy instead.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1295
 */

/**
 * Value+key form: InvoiceCollection narrows both from Invoice's @property annotations,
 * exactly like the direct-Builder case in BuilderPluckCustomBuilderTest.phpt.
 */
function test_pluck_value_and_key_on_custom_collection_subclass(InvoiceCollection $collection): void
{
    $_result = $collection->pluck('invoice_number', 'status');
    /** @psalm-check-type-exact $_result = Collection<string, string> */
}

/**
 * Regression guard: a non-generic custom BUILDER subclass must be unaffected by the
 * new Collection-side fallback — the mirror image of the "non-Builder subclass
 * unaffected" guard #1287 added for the Builder-side fallback. InvoiceBuilder's
 * classlike storage has no template_extended_params[...Collection::class] entry
 * (Builder never extends Collection), so the new fallback contributes nothing here;
 * this continues to resolve via the pre-existing #1287 Builder-side fallback only.
 */
function test_pluck_on_custom_builder_subclass_unaffected_by_collection_fallback(InvoiceBuilder $builder): void
{
    $_result = $builder->pluck('invoice_number', 'status');
    /** @psalm-check-type-exact $_result = Collection<string, string> */
}
?>
--EXPECTF--
