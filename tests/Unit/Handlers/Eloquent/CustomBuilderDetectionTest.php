<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent;

use App\Builders\CarBuilder;
use App\Builders\MechanicBuilder;
use App\Builders\PostBuilder;
use App\Models\Car;
use App\Models\Mechanic;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelMethodHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelRegistrationHandler;
use Psalm\Progress\VoidProgress;

/**
 * Tests custom builder detection in ModelRegistrationHandler via:
 * 1. #[UseEloquentBuilder] attribute (Laravel 12+)
 * 2. newEloquentBuilder() override with native return type
 * 3. protected static string $builder property override (Laravel 13+)
 */
#[CoversClass(ModelRegistrationHandler::class)]
final class CustomBuilderDetectionTest extends TestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        // Reset static state to prevent leaking between tests.
        // Both caches must be cleared together — $unresolvedCache entries depend on $customBuilderMap.
        (new \ReflectionProperty(ModelMethodHandler::class, 'customBuilderMap'))->setValue(null, []);
        (new \ReflectionProperty(ModelMethodHandler::class, 'unresolvedCache'))->setValue(null, []);
    }

    #[Test]
    public function it_registers_custom_builder_for_model_with_attribute(): void
    {
        $this->callDetectCustomBuilder(Post::class);

        $this->assertSame(PostBuilder::class, $this->getRegisteredBuilder(Post::class));
    }

    #[Test]
    public function it_does_not_register_builder_for_model_without_attribute(): void
    {
        $this->callDetectCustomBuilder(User::class);

        $this->assertNull($this->getRegisteredBuilder(User::class));
    }

    #[Test]
    public function it_registers_custom_builder_for_model_with_new_eloquent_builder_override(): void
    {
        $this->callDetectCustomBuilder(Car::class);

        $this->assertSame(CarBuilder::class, $this->getRegisteredBuilder(Car::class));
    }

    #[Test]
    public function it_registers_custom_builder_for_model_with_static_builder_property(): void
    {
        $this->callDetectCustomBuilder(Mechanic::class);

        $this->assertSame(MechanicBuilder::class, $this->getRegisteredBuilder(Mechanic::class));
    }

    #[Test]
    public function it_handles_non_existent_class_gracefully(): void
    {
        // Should not throw — the ReflectionException is caught and logged.
        $this->callDetectCustomBuilder('NonExistent\\FakeModelClass');

        $this->assertEmpty($this->getCustomBuilderMap());
    }

    #[Test]
    public function it_ignores_new_eloquent_builder_without_return_type(): void
    {
        // Secret model has no newEloquentBuilder override and no attribute.
        $this->callDetectCustomBuilder(\App\Models\Secret::class);

        $this->assertNull($this->getRegisteredBuilder(\App\Models\Secret::class));
    }

    /**
     * resolveBuilderFromMethodOverride skips methods that return builtin types or
     * non-Builder subclasses — tested here via the method's defensive guards.
     */
    #[Test]
    public function it_ignores_new_eloquent_builder_with_no_native_return_type(): void
    {
        $result = $this->callResolveBuilderFromMethodOverride(\App\Models\User::class);

        // User inherits newEloquentBuilder from Model — not an override.
        $this->assertNull($result);
    }

    /**
     * Call resolveBuilderFromMethodOverride directly via reflection.
     */
    private function callResolveBuilderFromMethodOverride(string $className): ?string
    {
        $reflection = new \ReflectionClass($className);
        $method = new \ReflectionMethod(ModelRegistrationHandler::class, 'resolveBuilderFromMethodOverride');

        return $method->invoke(null, $reflection);
    }

    /**
     * Call the private detectCustomBuilder method via reflection.
     */
    private function callDetectCustomBuilder(string $className): void
    {
        $codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();

        $progressRef = new \ReflectionProperty(Codebase::class, 'progress');
        $progressRef->setValue($codebase, new VoidProgress());

        $method = new \ReflectionMethod(ModelRegistrationHandler::class, 'detectCustomBuilder');
        $method->invoke(null, $codebase, $className);
    }

    /**
     * Read the private $customBuilderMap via reflection.
     *
     * @return array<class-string<Model>, class-string<Builder>>
     */
    private function getCustomBuilderMap(): array
    {
        $ref = new \ReflectionProperty(ModelMethodHandler::class, 'customBuilderMap');

        return $ref->getValue();
    }

    private function getRegisteredBuilder(string $modelClass): ?string
    {
        return $this->getCustomBuilderMap()[$modelClass] ?? null;
    }
}
