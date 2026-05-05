--FILE--
<?php declare(strict_types=1);

use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Verifies the static fallback in FactoryRegistrationHandler.
 *
 * The factory below is declared inline in this PHPT file and is therefore not
 * autoloadable in the analysis environment, so resolveViaReflection() returns
 * null. The fallback path reads TModel from `@extends Factory<WorkOrder>` via
 * Psalm's classlike storage instead, which keeps the magic for*()/has*() methods
 * type-checking even when runtime reflection is unavailable.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/696
 *
 * @extends Factory<WorkOrder>
 */
class TemplatedWorkOrderFactory extends Factory
{
    /** @var class-string<WorkOrder> */
    protected $model = WorkOrder::class;

    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

function test_template_binding_for(TemplatedWorkOrderFactory $factory): TemplatedWorkOrderFactory
{
    /** @psalm-check-type-exact $chained = TemplatedWorkOrderFactory */
    $chained = $factory->forVehicle();
    return $chained;
}

function test_template_binding_has(TemplatedWorkOrderFactory $factory): TemplatedWorkOrderFactory
{
    /** @psalm-check-type-exact $chained = TemplatedWorkOrderFactory */
    $chained = $factory->hasParts(2);
    return $chained;
}

function test_template_binding_chain(TemplatedWorkOrderFactory $factory): TemplatedWorkOrderFactory
{
    /** @psalm-check-type-exact $chained = TemplatedWorkOrderFactory */
    $chained = $factory->forVehicle()->hasParts(3);
    return $chained;
}
?>
--EXPECTF--
