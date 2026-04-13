--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-sealed.xml
--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Collection;

/**
 * With sealAllProperties="true", property writes on models must be recognized by Psalm.
 * The plugin registers pseudo_property_set_types so that relationship, legacy mutator, and new-style Attribute writes
 * don't trigger UndefinedMagicPropertyAssignment errors.
 *
 * Column writes are not tested here because the type test environment does not have
 * migration files in the Testbench default database path. Column write support is
 * covered by the unit test in ModelPropertyHandlerTest.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/446
 */

/** Relationship property write */
function test_relationship_write(Customer $customer, Collection $vehicles): void
{
    $customer->vehicles = $vehicles;
}

/** Legacy mutator write (setXxxAttribute) */
function test_legacy_mutator_write(Customer $customer): void
{
    $customer->nickname = 'alice';
}

/** New-style Attribute accessor write with typed TSet */
function test_attribute_accessor_write(Customer $customer): void
{
    $customer->first_name = 'Alice';
}

/** Attribute<TGet, never> must reject writes */
function test_readonly_attribute_rejects_write(Customer $customer): void
{
    $customer->display_name = 'should fail';
}

/** @property Vehicle|null $primary_vehicle takes precedence — write uses PHPDoc type, not plugin inference */
function test_property_annotation_precedence_on_write(Customer $customer, Vehicle $vehicle): void
{
    $customer->primary_vehicle = $vehicle;
}
?>
--EXPECTF--
UndefinedMagicPropertyAssignment on line %d: Magic instance property App\Models\Customer::$display_name is not defined
