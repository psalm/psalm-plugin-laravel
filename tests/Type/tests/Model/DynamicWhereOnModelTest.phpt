--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// Skip on Laravel < 12: this test relies on Laravel-12-only Eloquent attributes
// (#[Scope] / #[UseEloquentBuilder]), used directly or by a scanned app model.
// Those attribute classes do not exist on Laravel 11, so the asserted output
// cannot be produced there.
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('12.0.0');
--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Part;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tests for dynamic where{Column} resolution on direct Model static / instance calls.
 *
 * Before #1000 the plugin resolved dynamic where{Column} only on Relation chains
 * (HasMany, BelongsTo, ...). Calls directly on a Model raised UndefinedMagicMethod
 * even when the column existed as @property. ModelMethodHandler now reuses
 * DynamicWhereResolver to confirm existence and return Builder<TModel>.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1000
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/647
 */

// Static call on a bare-Builder Model — Customer has @property string $id.
function test_static_dynamic_where_single_column(): void {
    $_ = Customer::whereId('cust-1');
    /** @psalm-check-type-exact $_ = Builder<Customer> */
}

// Instance call — same path via __call rather than __callStatic.
function test_instance_dynamic_where_single_column(): void {
    $_ = (new Part())->whereName('Brake Pads');
    /** @psalm-check-type-exact $_ = Builder<Part> */
}

// Chained call — the returned Builder<TModel> must terminate with the model type.
function test_static_dynamic_where_chain_first(): void {
    $_ = Part::wherePartNumber('BP-1234')->first();
    /** @psalm-check-type-exact $_ = Part|null */
}

// Multi-segment And — Part has @property string $part_number and @property string $name.
function test_static_dynamic_where_multi_segment_and(): void {
    $_ = Part::wherePartNumberAndName('BP-1234', 'Brake Pads');
    /** @psalm-check-type-exact $_ = Builder<Part> */
}

// Multi-segment Or — same coverage as And.
function test_static_dynamic_where_multi_segment_or(): void {
    $_ = Part::wherePartNumberOrName('BP-1234', 'Brake Pads');
    /** @psalm-check-type-exact $_ = Builder<Part> */
}

// Custom builder via attribute — Invoice declares InvoiceBuilder via #[UseEloquentBuilder].
// The dynamic-where path must return the custom builder type, not Builder<Invoice>.
function test_static_dynamic_where_uses_attribute_custom_builder(): void {
    $_ = Invoice::whereStatus('paid');
    /** @psalm-check-type-exact $_ = App\Builders\InvoiceBuilder */
}

// Custom builder via newEloquentBuilder() override — Vehicle declares VehicleBuilder
// through the pre-attribute override path. The two custom-builder resolution routes
// run through different branches in ModelMethodHandler::builderType, so both need
// independent coverage.
function test_static_dynamic_where_uses_newEloquentBuilder_custom_builder(): void {
    $_ = Vehicle::whereMake('Toyota');
    /** @psalm-check-type-exact $_ = App\Builders\VehicleBuilder<Vehicle> */
}

// Underscored multi-word column — Customer has @property non-empty-string
// $first_name_using_legacy_accessor. "whereFirstNameUsingLegacyAccessor" normalises
// to "firstnameusinglegacyaccessor" and must still resolve.
function test_static_dynamic_where_underscored_multi_word_column(): void {
    $_ = Customer::whereFirstNameUsingLegacyAccessor('Alice');
    /** @psalm-check-type-exact $_ = Builder<Customer> */
}

// Typed-param hand-off (#928): scalar single-segment + 1 arg must reject wrong type.
function test_static_dynamic_where_rejects_wrong_scalar_type(): void {
    Part::whereName(123);
}

// Non-scalar (CarbonInterface|null) column falls through to variadic mixed.
function test_static_dynamic_where_skips_non_scalar_column(): void {
    Customer::whereEmailVerifiedAt('2024-01-01');
    Customer::whereEmailVerifiedAt(null);
}

// Multi-segment falls through to variadic mixed — wrong types in either position
// are tolerated (multi-segment intentionally skips typed-param hand-off, mirroring
// the relation-chain behaviour and Larastan's DynamicWhereParameterReflection).
function test_static_dynamic_where_multi_segment_falls_back_to_variadic(): void {
    Part::wherePartNumberAndName(123, 456);
}

// Unknown column on a Model with no matching @property: existence remains unconfirmed,
// Psalm continues to raise UndefinedMagicMethod. Without this guarantee, the looser
// lowercase backtrack matcher could over-accept arbitrary method names.
function test_static_dynamic_where_unknown_column_still_undefined(): void {
    Part::whereNonExistentColumn('x');
}

// Custom builder declares a where* method directly (VehicleBuilder::whereByMake).
// The dynamic-where path must NOT shadow the declared method — the fake-call branch
// resolves it from the custom builder's actual signature. Without the
// `!methodExists($builderClass, ...)` gate this would return Builder<Vehicle> generically.
function test_static_custom_builder_where_method_not_shadowed(): void {
    $_ = Vehicle::whereByMake('Toyota');
    /** @psalm-check-type-exact $_ = App\Builders\VehicleBuilder<Vehicle> */
}

// Lowercase non-splittable spelling (`whereid` vs `whereId`) still resolves to
// Builder<Customer> — Laravel's runtime lowercases method names too and treats
// `whereid` as the single-segment column `id`.
function test_static_dynamic_where_lowercase_spelling(): void {
    $_ = Customer::whereid('cust-1');
    /** @psalm-check-type-exact $_ = Builder<Customer> */
}

?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of App\Models\Part::wherename expects string, but 123 provided
UndefinedMagicMethod on line %d: Magic method App\Models\Part::wherenonexistentcolumn does not exist
