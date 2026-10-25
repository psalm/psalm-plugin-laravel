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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\CustomTypeDetector;
use Psalm\Progress\VoidProgress;

/**
 * Priority matches Laravel's HasCollection::newCollection():
 * 1. newCollection() override (bypasses everything when present)
 * 2. #[CollectedBy] attribute (checked first in base method, walks parent chain)
 * 3. protected static string $collectionClass property (fallback)
 */
#[CoversClass(CustomTypeDetector::class)]
final class CustomCollectionDetectionTest extends TestCase
{
    #[Test]
    public function it_resolves_the_custom_collection_from_each_declaration_form(): void
    {
        $codebase = $this->createCodebase();

        $this->assertSame(WorkOrderCollection::class, CustomTypeDetector::resolveCustomCollectionClass($codebase, WorkOrder::class));
        $this->assertSame(PartCollection::class, CustomTypeDetector::resolveCustomCollectionClass($codebase, Part::class));
        $this->assertSame(DamageReportCollection::class, CustomTypeDetector::resolveCustomCollectionClass($codebase, DamageReport::class));
    }

    #[Test]
    public function it_inherits_collected_by_from_parent_model(): void
    {
        // CollectedByChildModel extends CollectedByParentModel (#[CollectedBy(WorkOrderCollection::class)])
        // without its own attribute.
        $this->assertSame(
            WorkOrderCollection::class,
            CustomTypeDetector::resolveCustomCollectionClass($this->createCodebase(), Fixtures\CollectedByChildModel::class),
        );
    }

    #[Test]
    public function it_resolves_nothing_for_inherited_members_or_an_unloadable_class(): void
    {
        $codebase = $this->createCodebase();

        // Customer only inherits newCollection() and $collectionClass from Model: not overrides.
        $this->assertNull(CustomTypeDetector::resolveCustomCollectionClass($codebase, Customer::class));
        // The ReflectionException must be caught rather than thrown.
        $this->assertNull(CustomTypeDetector::resolveCustomCollectionClass($codebase, 'NonExistent\\FakeModelClass'));
    }

    private function createCodebase(): Codebase
    {
        $codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();

        $progressRef = new \ReflectionProperty(Codebase::class, 'progress');
        $progressRef->setValue($codebase, new VoidProgress());

        return $codebase;
    }
}
