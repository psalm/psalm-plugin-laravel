--SKIPIF--
<?php require getcwd() . '/vendor/autoload.php'; if (!\Composer\InstalledVersions::satisfies(new \Composer\Semver\VersionParser(), 'laravel/framework', '^12.0.0')) { echo 'skip requires Laravel 12+'; }
--FILE--
<?php declare(strict_types=1);

use App\Builders\BuilderMacroModelBuilder;
use App\Builders\VehicleBuilder;
use App\Builders\InvoiceBuilder;
use App\Builders\MechanicBuilder;
use App\Builders\WorkOrderBuilder;
use App\Collections\InvoiceCollection;
use App\Collections\WorkOrderCollection;
use App\Models\BuilderMacroModel;
use App\Models\Vehicle;
use App\Models\Invoice;
use App\Models\Mechanic;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Tests that models with custom query builders return the correct builder type
 * instead of base Eloquent\Builder.
 *
 * Three detection patterns are tested:
 * 1. #[UseEloquentBuilder] attribute (Laravel 12+) — WorkOrder model
 * 2. newEloquentBuilder() override with native return type — Vehicle model
 * 3. protected static string $builder property override (all Laravel versions) — Mechanic model
 *
 * @see https://laravel-news.com/defining-a-dedicated-query-builder-in-laravel-12-with-php-attributes
 */

/** WorkOrder::query() returns the custom builder, not base Builder. */
function test_query_returns_custom_builder(): void
{
    $_result = WorkOrder::query();
    /** @psalm-check-type-exact $_result = WorkOrderBuilder<WorkOrder> */
}

/** Custom builder methods are accessible via query(). */
function test_custom_method_via_query(): void
{
    $_result = WorkOrder::query()->whereCompleted();
    /** @psalm-check-type-exact $_result = WorkOrderBuilder<WorkOrder> */
}

/** Chaining custom builder method to terminal get(). */
function test_custom_method_chain_to_get(): void
{
    $_result = WorkOrder::query()->whereCompleted()->get();
    /** @psalm-check-type-exact $_result = WorkOrderCollection<int, WorkOrder> */
}

/** Multiple custom builder methods can be chained. */
function test_chain_multiple_custom_methods(): void
{
    $_result = WorkOrder::query()->whereCompleted()->wherePending();
    /** @psalm-check-type-exact $_result = WorkOrderBuilder<WorkOrder> */
}

/** Custom builder method chained into a base Builder method preserves the custom builder. */
function test_custom_method_chain_to_base_builder_method(): void
{
    $_result = WorkOrder::query()->whereCompleted()->where('priority', 1);
    /** @psalm-check-type-exact $_result = WorkOrderBuilder<WorkOrder>&static */
}

/**
 * Base Builder methods still work on the custom builder.
 *
 * Fluent Builder methods preserve the concrete custom builder type.
 */
function test_base_builder_methods_preserve_custom_builder(): void
{
    $_result = WorkOrder::query()->where('title', 'Hello');
    /** @psalm-check-type-exact $_result = WorkOrderBuilder<WorkOrder>&static */
}

/** Base Builder methods preserve custom builder type via static model forwarding. */
function test_base_builder_static_methods_preserve_custom_builder(): void
{
    $_result = WorkOrder::where('title', 'Hello');
    /** @psalm-check-type-exact $_result = WorkOrderBuilder<WorkOrder> */
}

/** Base Builder methods preserve custom builder type via instance model forwarding. */
function test_base_builder_instance_methods_preserve_custom_builder(): void
{
    $_result = (new WorkOrder())->where('title', 'Hello');
    /** @psalm-check-type-exact $_result = WorkOrderBuilder<WorkOrder> */
}

/** Custom builder methods accessible via static call on the model. */
function test_custom_method_via_static_call(): void
{
    $_result = WorkOrder::whereCompleted();
    /** @psalm-check-type-exact $_result = WorkOrderBuilder<WorkOrder> */
}

/** Static call chain: custom method -> get(). */
function test_static_custom_method_chain_to_get(): void
{
    $_result = WorkOrder::whereCompleted()->get();
    /** @psalm-check-type-exact $_result = WorkOrderCollection<int, WorkOrder> */
}

/** Regression: standard query methods still work via static call. */
function test_static_where_still_works(): void
{
    $_result = WorkOrder::where('title', 'Hello')->get();
    /** @psalm-check-type-exact $_result = WorkOrderCollection<int, WorkOrder> */
}

/** Terminal method first() preserves model type through custom builder. */
function test_first_via_custom_builder(): void
{
    $_result = WorkOrder::query()->whereCompleted()->first();
    /** @psalm-check-type-exact $_result = WorkOrder|null */
}

/** Terminal method find() preserves model type through custom builder. */
function test_find_via_custom_builder(): void
{
    $_result = WorkOrder::query()->whereCompleted()->find(1);
    /** @psalm-check-type-exact $_result = WorkOrder|null */
}

/**
 * Query\Builder-only method (whereIn) works via static call on custom builder model.
 *
 * Returns WorkOrderBuilder<WorkOrder>&static (with &static) because this goes through the
 * __callStatic -> executeFakeCall proxy path, which preserves the static intersection.
 * Custom builder methods (whereCompleted) go through getReturnTypeForForwardedMethod
 * which returns WorkOrderBuilder<WorkOrder> without &static.
 */
function test_query_builder_method_via_static_call(): void
{
    $_result = WorkOrder::whereIn('id', [1, 2, 3]);
    /** @psalm-check-type-exact $_result = WorkOrderBuilder<WorkOrder>&static */
}

/** Custom method with parameters — exercises the getMethodParams provider path. */
function test_custom_method_with_params(): void
{
    $_result = WorkOrder::query()->whereByMechanic(42);
    /** @psalm-check-type-exact $_result = WorkOrderBuilder<WorkOrder> */
}

/** Custom method with params via static call. */
function test_custom_method_with_params_static(): void
{
    $_result = WorkOrder::whereByMechanic(42);
    /** @psalm-check-type-exact $_result = WorkOrderBuilder<WorkOrder> */
}

/** Legacy scope on model with custom builder returns WorkOrderBuilder<WorkOrder> via static call. */
function test_scope_on_custom_builder_model(): void
{
    $_result = WorkOrder::urgent();
    /** @psalm-check-type-exact $_result = WorkOrderBuilder<WorkOrder> */
}

/** Legacy scope on model with custom builder via builder instance. */
function test_scope_on_custom_builder_via_query(): void
{
    $_result = WorkOrder::query()->urgent();
    /** @psalm-check-type-exact $_result = WorkOrderBuilder<WorkOrder> */
}

/** Scope chained with a custom builder method. */
function test_scope_chain_with_custom_method(): void
{
    $_result = WorkOrder::query()->urgent()->whereCompleted();
    /** @psalm-check-type-exact $_result = WorkOrderBuilder<WorkOrder> */
}

/** Scope chained to terminal get(). */
function test_scope_chain_to_get(): void
{
    $_result = WorkOrder::query()->urgent()->get();
    /** @psalm-check-type-exact $_result = WorkOrderCollection<int, WorkOrder> */
}

/** Modern #[Scope] attribute on model with custom builder via builder instance. */
function test_scope_attribute_on_custom_builder_via_query(): void
{
    $_result = WorkOrder::query()->completed();
    /** @psalm-check-type-exact $_result = WorkOrderBuilder<WorkOrder> */
}

/**
 * Known limitation: #[Scope] methods work at runtime via __callStatic -> query() -> Builder,
 * but Psalm sees them as real instance methods and reports InvalidStaticInvocation.
 * Same behavior as Customer::verified() in StaticBuilderMethodsTest.
 */
function test_scope_attribute_static_is_invalid_on_custom_builder(): void
{
    $_result = WorkOrder::completed();
}

/** Scope with parameters via builder instance — exercises getScopeParams path. */
function test_scope_with_params_on_custom_builder_via_query(): void
{
    $_result = WorkOrder::query()->byStatus('closed');
    /** @psalm-check-type-exact $_result = WorkOrderBuilder<WorkOrder> */
}

/** Negative test: nonexistent methods on builder instance must still be reported. */
function test_nonexistent_method_on_custom_builder_instance(): void
{
    $_result = WorkOrder::query()->completelyFakeMethod();
}

// -----------------------------------------------------------------------
// SoftDeletes trait methods on custom builder
// WorkOrder uses both #[UseEloquentBuilder(WorkOrderBuilder::class)] and SoftDeletes.
// The @method static annotations on SoftDeletes (withTrashed, onlyTrashed,
// withoutTrashed) must return WorkOrderBuilder<WorkOrder>, not base Builder<WorkOrder>.
// See https://github.com/psalm/psalm-plugin-laravel/issues/631
// -----------------------------------------------------------------------

/** Static call: trait-declared builder method returns custom builder. */
function test_soft_deletes_with_trashed_static(): void
{
    $_result = WorkOrder::withTrashed();
    /** @psalm-check-type-exact $_result = WorkOrderBuilder<WorkOrder> */
}

/** Static call: onlyTrashed also returns custom builder. */
function test_soft_deletes_only_trashed_static(): void
{
    $_result = WorkOrder::onlyTrashed();
    /** @psalm-check-type-exact $_result = WorkOrderBuilder<WorkOrder> */
}

/** Static call: withoutTrashed also returns custom builder. */
function test_soft_deletes_without_trashed_static(): void
{
    $_result = WorkOrder::withoutTrashed();
    /** @psalm-check-type-exact $_result = WorkOrderBuilder<WorkOrder> */
}

/** Builder instance call: withTrashed on custom builder. */
function test_soft_deletes_with_trashed_via_query(): void
{
    $_result = WorkOrder::query()->withTrashed();
    /** @psalm-check-type-exact $_result = WorkOrderBuilder<WorkOrder> */
}

/** Builder instance call: onlyTrashed on custom builder. */
function test_soft_deletes_only_trashed_via_query(): void
{
    $_result = WorkOrder::query()->onlyTrashed();
    /** @psalm-check-type-exact $_result = WorkOrderBuilder<WorkOrder> */
}

/** Builder instance call: chaining trait method with custom builder method. */
function test_soft_deletes_chain_with_custom_method(): void
{
    $_result = WorkOrder::query()->withTrashed()->whereCompleted();
    /** @psalm-check-type-exact $_result = WorkOrderBuilder<WorkOrder> */
}

/** Builder instance call: chaining trait method to terminal get(). */
function test_soft_deletes_chain_to_get(): void
{
    $_result = WorkOrder::query()->withTrashed()->get();
    /** @psalm-check-type-exact $_result = WorkOrderCollection<int, WorkOrder> */
}

/**
 * restoreOrCreate returns the model type (static), not the builder — must NOT be remapped.
 * The &static intersection comes from Psalm's native @method static resolution.
 */
function test_soft_deletes_restore_or_create_returns_model(): void
{
    $_result = WorkOrder::restoreOrCreate(['slug' => 'test']);
    /** @psalm-check-type-exact $_result = WorkOrder&static */
}

/** createOrRestore also returns the model type, not the builder. */
function test_soft_deletes_create_or_restore_returns_model(): void
{
    $_result = WorkOrder::createOrRestore(['slug' => 'test']);
    /** @psalm-check-type-exact $_result = WorkOrder&static */
}

// -----------------------------------------------------------------------
// newEloquentBuilder() override pattern (pre-Laravel 12)
// Vehicle model overrides newEloquentBuilder() with a native return type.
// -----------------------------------------------------------------------

/** Vehicle::query() returns the custom builder via newEloquentBuilder() override. */
function test_new_eloquent_builder_query(): void
{
    $_result = Vehicle::query();
    /** @psalm-check-type-exact $_result = VehicleBuilder<Vehicle> */
}

/** Custom builder methods work via query() on newEloquentBuilder model. */
function test_new_eloquent_builder_custom_method(): void
{
    $_result = Vehicle::query()->whereElectric();
    /** @psalm-check-type-exact $_result = VehicleBuilder<Vehicle> */
}

/** Custom builder methods work via static call on newEloquentBuilder model. */
function test_new_eloquent_builder_static_call(): void
{
    $_result = Vehicle::whereElectric();
    /** @psalm-check-type-exact $_result = VehicleBuilder<Vehicle> */
}

/** Terminal method through newEloquentBuilder custom builder. */
function test_new_eloquent_builder_terminal(): void
{
    $_result = Vehicle::query()->whereElectric()->get();
    /** @psalm-check-type-exact $_result = Collection<int, Vehicle> */
}

/** Scope on newEloquentBuilder model via builder instance. */
function test_scope_on_new_eloquent_builder_via_query(): void
{
    $_result = Vehicle::query()->byMake('Toyota');
    /** @psalm-check-type-exact $_result = VehicleBuilder<Vehicle> */
}

// -----------------------------------------------------------------------
// static $builder property pattern (all Laravel versions)
// Mechanic model sets protected static string $builder = MechanicBuilder::class.
// -----------------------------------------------------------------------

/** Mechanic::query() returns the custom builder via static $builder property. */
function test_static_builder_property_query(): void
{
    $_result = Mechanic::query();
    /** @psalm-check-type-exact $_result = MechanicBuilder<Mechanic> */
}

/** Custom builder methods work via query() on static $builder model. */
function test_static_builder_property_custom_method(): void
{
    $_result = Mechanic::query()->whereCertified();
    /** @psalm-check-type-exact $_result = MechanicBuilder<Mechanic> */
}

/** Custom builder methods work via static call on static $builder model. */
function test_static_builder_property_static_call(): void
{
    $_result = Mechanic::whereCertified();
    /** @psalm-check-type-exact $_result = MechanicBuilder<Mechanic> */
}

/** Scope on static $builder property model via builder instance. */
function test_scope_on_static_builder_property_via_query(): void
{
    $_result = Mechanic::query()->experienced();
    /** @psalm-check-type-exact $_result = MechanicBuilder<Mechanic> */
}

/** Negative test: nonexistent methods must still be reported. */
function test_nonexistent_method_on_custom_builder_model(): void
{
    $_result = WorkOrder::completelyFakeMethod();
}

// -----------------------------------------------------------------------
// Custom builder without template parameters
// InvoiceBuilder extends Builder<Invoice> without declaring its own @template.
// Should return plain InvoiceBuilder (TNamedObject), not InvoiceBuilder<Invoice>
// (TGenericObject) which would trigger TooManyTemplateParams.
// -----------------------------------------------------------------------

/** Non-template builder: query() returns plain InvoiceBuilder. */
function test_non_template_builder_query(): void
{
    $_result = Invoice::query();
    /** @psalm-check-type-exact $_result = InvoiceBuilder */
}

/** Non-template builder: custom method still works. */
function test_non_template_builder_custom_method(): void
{
    $_result = Invoice::query()->wherePaid();
    /** @psalm-check-type-exact $_result = InvoiceBuilder */
}

/** Non-template builder: custom method via static call. */
function test_non_template_builder_static_call(): void
{
    $_result = Invoice::wherePaid();
    /** @psalm-check-type-exact $_result = InvoiceBuilder */
}

/** Non-template builder: base Builder method via static call preserves plain InvoiceBuilder. */
function test_non_template_builder_base_static_call(): void
{
    $_result = Invoice::where('status', 'paid');
    /** @psalm-check-type-exact $_result = InvoiceBuilder */
}

/** Non-template builder: base Builder method via instance call preserves plain InvoiceBuilder. */
function test_non_template_builder_base_instance_call(): void
{
    $_result = (new Invoice())->where('status', 'paid');
    /** @psalm-check-type-exact $_result = InvoiceBuilder */
}

/** Non-template builder: model-level @method returning the custom builder is also a builder macro. */
function test_model_level_custom_builder_macro_static_call(): void
{
    $_result = BuilderMacroModel::activeOnly();
    /** @psalm-check-type-exact $_result = BuilderMacroModelBuilder */
}

/** Model-level @method returning a custom builder preserves static-side SoftDeletes wiring. */
function test_model_level_custom_builder_soft_deletes_static_call(): void
{
    $_result = BuilderMacroModel::onlyTrashed();
    /** @psalm-check-type-exact $_result = BuilderMacroModelBuilder */
}

/** Model-level @method returning a custom builder is also a builder macro. */
function test_model_level_custom_builder_macro_instance_call(BuilderMacroModelBuilder $query): void
{
    $_result = $query->activeOnly();
    /** @psalm-check-type-exact $_result = BuilderMacroModelBuilder */
}

/** Non-template builder: base Builder method chain must keep model-level trait macros. */
function test_model_level_custom_builder_macro_after_base_builder_method(BuilderMacroModelBuilder $query): void
{
    $_result = $query->where('deleted_at', '<', '2024-01-01')->onlyTrashed();
    /** @psalm-check-type-exact $_result = BuilderMacroModelBuilder */
}

/** Negative: unrelated model-level @method returns are not registered as builder macros. */
function test_unrelated_model_level_method_is_not_registered_on_custom_builder(BuilderMacroModelBuilder $query): void
{
    $_result = $query->unrelatedMacro();
}

/**
 * Non-template builder: terminal get() returns base Collection, not InvoiceCollection.
 *
 * CustomCollectionHandler relies on Builder<TModel> template params to identify the model.
 * A non-template builder (plain TNamedObject) doesn't carry template params, so collection
 * narrowing through the builder path doesn't apply. Use Model::all() for custom collection.
 */
function test_non_template_builder_terminal_get(): void
{
    $_result = Invoice::query()->get();
    /** @psalm-check-type-exact $_result = Collection<int, Invoice> */
}

/** Non-template builder: first() preserves model type. */
function test_non_template_builder_terminal_first(): void
{
    $_result = Invoice::query()->first();
    /** @psalm-check-type-exact $_result = Invoice|null */
}

/** Non-template collection: Model::all() returns plain InvoiceCollection. */
function test_non_template_collection_all(): void
{
    $_result = Invoice::all();
    /** @psalm-check-type-exact $_result = InvoiceCollection */
}
?>
--EXPECTF--
InvalidStaticInvocation on line %d: Method App\Models\WorkOrder::completed is not static, but is called statically
UndefinedMagicMethod on line %d: Magic method App\Builders\WorkOrderBuilder::completelyfakemethod does not exist
UndefinedMagicMethod on line %d: Magic method App\Models\WorkOrder::completelyfakemethod does not exist
UndefinedMagicMethod on line %d: Magic method App\Builders\BuilderMacroModelBuilder::unrelatedmacro does not exist
