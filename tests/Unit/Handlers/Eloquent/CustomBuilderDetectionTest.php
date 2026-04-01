<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent;

use App\Builders\CarBuilder;
use App\Builders\PostBuilder;
use App\Models\Car;
use App\Models\Post;
use App\Models\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelMethodHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelRegistrationHandler;
use Psalm\Progress\VoidProgress;

/**
 * Tests custom builder detection in ModelRegistrationHandler via both:
 * 1. #[UseEloquentBuilder] attribute (Laravel 12+)
 * 2. newEloquentBuilder() override with native return type
 */
#[CoversClass(ModelRegistrationHandler::class)]
final class CustomBuilderDetectionTest extends TestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        // Reset static state to prevent leaking between tests.
        $ref = new \ReflectionProperty(ModelMethodHandler::class, 'customBuilderMap');
        $ref->setValue(null, []);
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
    public function it_handles_non_existent_class_gracefully(): void
    {
        // Should not throw — the ReflectionException is caught and logged.
        $this->callDetectCustomBuilder('NonExistent\\FakeModelClass');

        $this->assertEmpty($this->getCustomBuilderMap());
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
     * @return array<string, string>
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
