--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use App\Models\SerializableModel;

/**
 * With modelToArrayShape NOT enabled (the default — no <experimental> element in
 * tests/Type/psalm.xml), attributesToArray()/toArray() must stay Laravel's native
 * array<string, mixed> even for a model whose $appends WOULD produce a narrowed shape once
 * the feature is turned on. See ToArrayShapeTest.phpt for the enabled behavior.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1203
 *
 * @return array<string, mixed>
 */
function test_to_array_stays_generic_when_the_experiment_is_disabled(SerializableModel $model): array
{
    /** @psalm-check-type-exact $shape = array<string, mixed> */
    $shape = $model->toArray();

    return $shape;
}

/**
 * @return array<string, mixed>
 */
function test_attributes_to_array_stays_generic_when_the_experiment_is_disabled(SerializableModel $model): array
{
    /** @psalm-check-type-exact $shape = array<string, mixed> */
    $shape = $model->attributesToArray();

    return $shape;
}
?>
--EXPECTF--
