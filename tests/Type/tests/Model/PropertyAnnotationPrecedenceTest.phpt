--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use App\Models\Customer;
use App\Models\Vehicle;
use Carbon\CarbonInterface;

/**
 * @property annotations on a model should take precedence over plugin inference.
 *
 * Customer model declares:
 *   @property string $id                                         - overrides migration-inferred int
 *   @property Vehicle|null $primary_vehicle                      - overrides relationship-inferred Vehicle
 *   @property non-empty-string $first_name_using_legacy_accessor - overrides accessor-inferred string
 */

/** Column: @property string $id wins over migration-inferred int<0, max> */
function test_property_annotation_overrides_column_type(Customer $customer): string
{
    /** @psalm-check-type-exact $id = string */
    $id = $customer->id;
    return $id;
}

/** Relationship: @property Vehicle|null $primary_vehicle wins over relationship-inferred Vehicle */
function test_property_annotation_overrides_relationship_type(Customer $customer): ?Vehicle
{
    /** @psalm-check-type-exact $vehicle = Vehicle|null */
    $vehicle = $customer->primary_vehicle;
    return $vehicle;
}

/** Accessor: @property non-empty-string $first_name_using_legacy_accessor wins over accessor-inferred string */
function test_property_annotation_overrides_accessor_type(Customer $customer): string
{
    /** @psalm-check-type-exact $name = non-empty-string */
    $name = $customer->first_name_using_legacy_accessor;
    return $name;
}
?>
--EXPECTF--
