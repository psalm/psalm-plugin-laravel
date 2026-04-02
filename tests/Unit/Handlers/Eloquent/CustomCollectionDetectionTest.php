<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent;

use App\Collections\PostCollection;
use App\Collections\SecretCollection;
use App\Collections\TagCollection;
use App\Models\Post;
use App\Models\Secret;
use App\Models\Tag;
use App\Models\User;
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
        $this->callDetectCustomCollection(Post::class);

        $this->assertSame(PostCollection::class, $this->getRegisteredCollection(Post::class));
    }

    #[Test]
    public function it_registers_custom_collection_for_model_with_new_collection_override(): void
    {
        $this->callDetectCustomCollection(Secret::class);

        $this->assertSame(SecretCollection::class, $this->getRegisteredCollection(Secret::class));
    }

    #[Test]
    public function it_registers_custom_collection_for_model_with_collection_class_property(): void
    {
        $this->callDetectCustomCollection(Tag::class);

        $this->assertSame(TagCollection::class, $this->getRegisteredCollection(Tag::class));
    }

    #[Test]
    public function it_does_not_register_collection_for_model_without_custom_collection(): void
    {
        $this->callDetectCustomCollection(User::class);

        $this->assertNull($this->getRegisteredCollection(User::class));
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
        // CollectedByParentModel has #[CollectedBy(PostCollection::class)],
        // CollectedByChildModel extends it without its own attribute — should inherit.
        $this->callDetectCustomCollection(Fixtures\CollectedByChildModel::class);

        $this->assertSame(PostCollection::class, $this->getRegisteredCollection(Fixtures\CollectedByChildModel::class));
    }

    #[Test]
    public function it_resolves_collection_from_attribute_directly(): void
    {
        $result = $this->callResolveCollectionFromAttribute(Post::class);

        $this->assertSame(PostCollection::class, $result);
    }

    #[Test]
    public function it_returns_null_for_inherited_new_collection(): void
    {
        // User inherits newCollection from Model — not an override, so no custom collection.
        $result = $this->callResolveCollectionFromMethodOverride(User::class);

        $this->assertNull($result);
    }

    #[Test]
    public function it_resolves_collection_from_static_property_directly(): void
    {
        $result = $this->callResolveCollectionFromStaticProperty(Tag::class);

        $this->assertSame(TagCollection::class, $result);
    }

    #[Test]
    public function it_returns_null_for_inherited_static_collection_class_property(): void
    {
        // User inherits $collectionClass from Model — not an override.
        $result = $this->callResolveCollectionFromStaticProperty(User::class);

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
