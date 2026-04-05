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
use Psalm\LaravelPlugin\Handlers\Eloquent\CustomBuilderMethodHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelMethodHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelRegistrationHandler;
use Psalm\Progress\VoidProgress;

/**
 * Tests custom builder detection in ModelRegistrationHandler.
 *
 * Priority matches Laravel's Model::newEloquentBuilder():
 * 1. newEloquentBuilder() override (bypasses everything when present)
 * 2. #[UseEloquentBuilder] attribute (checked first in base method)
 * 3. protected static string $builder property (fallback)
 */
#[CoversClass(ModelRegistrationHandler::class)]
final class CustomBuilderDetectionTest extends TestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        // Reset static state to prevent leaking between tests.
        // All maps and caches must be cleared together — they are interdependent.
        (new \ReflectionProperty(ModelMethodHandler::class, 'customBuilderMap'))->setValue(null, []);
        (new \ReflectionProperty(ModelMethodHandler::class, 'unresolvedCache'))->setValue(null, []);
        (new \ReflectionProperty(CustomBuilderMethodHandler::class, 'builderToModelMap'))->setValue(null, []);
        (new \ReflectionProperty(CustomBuilderMethodHandler::class, 'traitBuilderMethods'))->setValue(null, []);
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
    public function it_returns_null_for_inherited_new_eloquent_builder(): void
    {
        // User inherits newEloquentBuilder from Model — not an override, so no custom builder.
        $result = $this->callResolveBuilderFromMethodOverride(User::class);

        $this->assertNull($result);
    }

    #[Test]
    public function it_resolves_builder_from_static_property_directly(): void
    {
        $result = $this->callResolveBuilderFromStaticProperty(Mechanic::class);

        $this->assertSame(MechanicBuilder::class, $result);
    }

    #[Test]
    public function it_returns_null_for_inherited_static_builder_property(): void
    {
        // User inherits $builder from Model — not an override.
        $result = $this->callResolveBuilderFromStaticProperty(User::class);

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
     * Call resolveBuilderFromStaticProperty directly via reflection.
     */
    private function callResolveBuilderFromStaticProperty(string $className): ?string
    {
        $reflection = new \ReflectionClass($className);
        $method = new \ReflectionMethod(ModelRegistrationHandler::class, 'resolveBuilderFromStaticProperty');

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
