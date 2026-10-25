<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent;

use App\Builders\MechanicBuilder;
use App\Builders\VehicleBuilder;
use App\Builders\WorkOrderBuilder;
use App\Models\Customer;
use App\Models\Mechanic;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\CustomTypeDetector;
use Psalm\Progress\VoidProgress;

/**
 * Priority matches Laravel's Model::newEloquentBuilder():
 * 1. newEloquentBuilder() override (bypasses everything when present)
 * 2. #[UseEloquentBuilder] attribute (checked first in base method)
 * 3. protected static string $builder property (fallback)
 */
#[CoversClass(CustomTypeDetector::class)]
final class CustomBuilderDetectionTest extends TestCase
{
    #[Test]
    public function it_resolves_the_custom_builder_from_each_declaration_form(): void
    {
        $codebase = $this->createCodebase();

        $this->assertSame(WorkOrderBuilder::class, CustomTypeDetector::resolveCustomBuilderClass($codebase, WorkOrder::class));
        $this->assertSame(VehicleBuilder::class, CustomTypeDetector::resolveCustomBuilderClass($codebase, Vehicle::class));
        $this->assertSame(MechanicBuilder::class, CustomTypeDetector::resolveCustomBuilderClass($codebase, Mechanic::class));
    }

    #[Test]
    public function it_resolves_nothing_for_inherited_members_or_an_unloadable_class(): void
    {
        $codebase = $this->createCodebase();

        // Customer only inherits newEloquentBuilder() and $builder from Model: not overrides.
        $this->assertNull(CustomTypeDetector::resolveCustomBuilderClass($codebase, Customer::class));
        // The ReflectionException must be caught rather than thrown.
        $this->assertNull(CustomTypeDetector::resolveCustomBuilderClass($codebase, 'NonExistent\\FakeModelClass'));
    }

    private function createCodebase(): Codebase
    {
        $codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();

        $progressRef = new \ReflectionProperty(Codebase::class, 'progress');
        $progressRef->setValue($codebase, new VoidProgress());

        return $codebase;
    }
}
