--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use App\Models\Customer;

/**
 * Schema-empty gate for the attributesToArray()/toArray() shape inference.
 *
 * The type-test harness boots Testbench with no migrations, so app models have an empty schema.
 * ModelToArrayShapeHandler must then defer to the stub's `array<string, mixed>` rather than emit a
 * shape that omits every real column. This pins that gate: if the schema-empty bail regressed, the
 * handler would emit a (column-less) shape and these exact-type checks would break.
 *
 * The positive shape (real columns/$appends/$hidden/$visible) is covered in
 * tests/Unit/Handlers/Eloquent/ModelToArrayShapeHandlerTest.php, which can build a non-empty schema.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/923
 */
/**
 * @return array<string, mixed>
 */
function test_to_array_falls_back_without_schema(Customer $customer): array
{
    /** @psalm-check-type-exact $shape = array<string, mixed> */
    $shape = $customer->toArray();

    return $shape;
}

/**
 * @return array<string, mixed>
 */
function test_attributes_to_array_falls_back_without_schema(Customer $customer): array
{
    /** @psalm-check-type-exact $shape = array<string, mixed> */
    $shape = $customer->attributesToArray();

    return $shape;
}
?>
--EXPECTF--
