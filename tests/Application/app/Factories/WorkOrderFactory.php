<?php

declare(strict_types=1);

namespace App\Factories;

use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test fixture covering the most common Factory pattern: explicit `protected $model`.
 *
 * Used by tests/Type/tests/Factory/MagicMethodsTest.phpt to verify that
 * forVehicle() / hasParts() and friends are recognized as magic methods returning
 * the same factory type.
 *
 * @extends Factory<WorkOrder>
 */
final class WorkOrderFactory extends Factory
{
    /**
     * @var class-string<WorkOrder>
     */
    protected $model = WorkOrder::class;

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}
