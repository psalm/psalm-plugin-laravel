<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent;

use App\Collections\DamageReportCollection;
use App\Collections\PartCollection;
use App\Collections\WorkOrderCollection;
use App\Models\Customer;
use App\Models\DamageReport;
use App\Models\Part;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Handlers\Eloquent\CustomCollectionHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelRegistrationHandler;
use Psalm\Progress\VoidProgress;

/**
 * Tests custom collection detection in ModelRegistrationHandler.
 *
 * Priority matches Laravel's HasCollection::newCollection():
 * 1. newCollection() override (bypasses everything when present)
 * 2. #[CollectedBy] attribute (checked first in base method, walks parent chain)
 * 3. protected static string $collectionClass property (fallback)
 */
#[CoversClass(ModelRegistrationHandler::class)]
final class CustomCollectionDetectionTest extends TestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        // Reset static state to prevent leaking between tests.
        (new \ReflectionProperty(CustomCollectionHandler::class, 'modelToCollectionMap'))->setValue(null, []);
    }

    #[Test]
    public function it_registers_custom_collection_for_model_with_collected_by_attribute(): void
    {
        $this->callDetectCustomCollection(WorkOrder::class);

        $this->assertSame(WorkOrderCollection::class, $this->getRegisteredCollection(WorkOrder::class));
    }

    #[Test]
    public function it_registers_custom_collection_for_model_with_new_collection_override(): void
    {
        $this->callDetectCustomCollection(Part::class);

        $this->assertSame(PartCollection::class, $this->getRegisteredCollection(Part::class));
    }

    #[Test]
    public function it_registers_custom_collection_for_model_with_collection_class_property(): void
    {
        $this->callDetectCustomCollection(DamageReport::class);

        $this->assertSame(DamageReportCollection::class, $this->getRegisteredCollection(DamageReport::class));
    }

    #[Test]
    public function it_does_not_register_collection_for_model_without_custom_collection(): void
    {
        $this->callDetectCustomCollection(Customer::class);

        $this->assertNull($this->getRegisteredCollection(Customer::class));
    }

    #[Test]
    public function it_handles_non_existent_class_gracefully(): void
    {
        // Should not throw — the ReflectionException is caught and logged.
        $this->callDetectCustomCollection('NonExistent\\FakeModelClass');

        $this->assertEmpty($this->getCollectionMap());
    }

    #[Test]
    public function it_inherits_collected_by_from_parent_model(): void
    {
        // CollectedByParentModel has #[CollectedBy(WorkOrderCollection::class)],
        // CollectedByChildModel extends it without its own attribute — should inherit.
        $this->callDetectCustomCollection(Fixtures\CollectedByChildModel::class);

        $this->assertSame(WorkOrderCollection::class, $this->getRegisteredCollection(Fixtures\CollectedByChildModel::class));
    }

    #[Test]
    public function it_resolves_collection_from_attribute_directly(): void
    {
        $result = $this->callResolveCollectionFromAttribute(WorkOrder::class);

        $this->assertSame(WorkOrderCollection::class, $result);
    }

    #[Test]
    public function it_returns_null_for_inherited_new_collection(): void
    {
        // Customer inherits newCollection from Model — not an override, so no custom collection.
        $result = $this->callResolveCollectionFromMethodOverride(Customer::class);

        $this->assertNull($result);
    }

    #[Test]
    public function it_resolves_collection_from_static_property_directly(): void
    {
        $result = $this->callResolveCollectionFromStaticProperty(DamageReport::class);

        $this->assertSame(DamageReportCollection::class, $result);
    }

    #[Test]
    public function it_returns_null_for_inherited_static_collection_class_property(): void
    {
        // Customer inherits $collectionClass from Model — not an override.
        $result = $this->callResolveCollectionFromStaticProperty(Customer::class);

        $this->assertNull($result);
    }

    /**
     * Call resolveCollectionFromAttribute directly via reflection.
     */
    private function callResolveCollectionFromAttribute(string $className): ?string
    {
        $reflection = new \ReflectionClass($className);
        $method = new \ReflectionMethod(ModelRegistrationHandler::class, 'resolveCollectionFromAttribute');
        $codebase = $this->createCodebase();

        return $method->invoke(null, $reflection, $codebase);
    }

    /**
     * Call resolveCollectionFromMethodOverride directly via reflection.
     */
    private function callResolveCollectionFromMethodOverride(string $className): ?string
    {
        $reflection = new \ReflectionClass($className);
        $method = new \ReflectionMethod(ModelRegistrationHandler::class, 'resolveCollectionFromMethodOverride');

        return $method->invoke(null, $reflection);
    }

    /**
     * Call resolveCollectionFromStaticProperty directly via reflection.
     */
    private function callResolveCollectionFromStaticProperty(string $className): ?string
    {
        $reflection = new \ReflectionClass($className);
        $method = new \ReflectionMethod(ModelRegistrationHandler::class, 'resolveCollectionFromStaticProperty');

        return $method->invoke(null, $reflection);
    }

    /**
     * Call the private detectCustomCollection method via reflection.
     */
    private function callDetectCustomCollection(string $className): void
    {
        $method = new \ReflectionMethod(ModelRegistrationHandler::class, 'detectCustomCollection');
        $method->invoke(null, $this->createCodebase(), $className);
    }

    private function createCodebase(): Codebase
    {
        $codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();

        $progressRef = new \ReflectionProperty(Codebase::class, 'progress');
        $progressRef->setValue($codebase, new VoidProgress());

        return $codebase;
    }

    /**
     * Read the private $modelToCollectionMap via reflection.
     *
     * @return array<string, string>
     */
    private function getCollectionMap(): array
    {
        $ref = new \ReflectionProperty(CustomCollectionHandler::class, 'modelToCollectionMap');

        return $ref->getValue();
    }

    private function getRegisteredCollection(string $modelClass): ?string
    {
        return $this->getCollectionMap()[$modelClass] ?? null;
    }
}
