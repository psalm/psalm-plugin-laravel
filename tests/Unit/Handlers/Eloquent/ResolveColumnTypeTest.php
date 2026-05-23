<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent;

use App\Models\WorkOrder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelPropertyHandler;
use Psalm\Type;

/**
 * Covers the `@property` short-circuit path of {@see ModelPropertyHandler::resolveColumnType}
 * (#1004), which {@see \Psalm\LaravelPlugin\Handlers\Eloquent\BuilderAggregateHandler} relies on
 * for column-type narrowing.
 *
 * The schema and cast branches reach into `CastsMethodParser`, which dereferences
 * `Codebase::$methods` — that property requires a fully-wired `Psalm\Internal\Codebase\Methods`
 * instance (with `ClassLikes`, `FileReferenceProvider`, …) that cannot be cheaply faked in a
 * unit test. Those branches are exercised end-to-end by the integration / app-test layer.
 */
#[CoversClass(ModelPropertyHandler::class)]
final class ResolveColumnTypeTest extends TestCase
{
    private ClassLikeStorageProvider $classLikeStorageProvider;

    private Codebase $codebase;

    #[\Override]
    protected function setUp(): void
    {
        $this->classLikeStorageProvider = new ClassLikeStorageProvider();
        $this->classLikeStorageProvider->create(WorkOrder::class);

        $codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();
        $codebase->classlike_storage_provider = $this->classLikeStorageProvider;
        $this->codebase = $codebase;
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->classLikeStorageProvider->remove(WorkOrder::class);
        // Reset the schema set by `it_returns_null_when_no_property_and_no_schema`
        // so later unit tests in the suite don't see a stray empty aggregator.
        \Psalm\LaravelPlugin\Providers\SchemaStateProvider::setSchema(
            new \Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator(),
        );
    }

    #[Test]
    public function it_returns_pseudo_property_get_type(): void
    {
        $storage = $this->classLikeStorageProvider->get(WorkOrder::class);
        $storage->pseudo_property_get_types['$amount_cents'] = Type::getInt();

        $type = ModelPropertyHandler::resolveColumnType($this->codebase, WorkOrder::class, 'amount_cents');

        $this->assertNotNull($type);
        $this->assertSame('int', (string) $type);
    }

    #[Test]
    public function it_returns_user_property_with_object_type(): void
    {
        // Class-typed @property — proves resolveColumnType passes through non-scalar Unions
        // (e.g. `@property CarbonInterface|null $created_at`) without rewriting them.
        $storage = $this->classLikeStorageProvider->get(WorkOrder::class);
        $storage->pseudo_property_get_types['$created_at'] = new \Psalm\Type\Union([
            new \Psalm\Type\Atomic\TNamedObject(\Carbon\CarbonInterface::class),
            new \Psalm\Type\Atomic\TNull(),
        ]);

        $type = ModelPropertyHandler::resolveColumnType($this->codebase, WorkOrder::class, 'created_at');

        $this->assertNotNull($type);
        $this->assertSame('Carbon\CarbonInterface|null', (string) $type);
    }

    #[Test]
    public function it_returns_null_when_no_property_and_no_schema(): void
    {
        // No pseudo_property_get_types set; SchemaStateProvider has no schema for work_orders.
        \Psalm\LaravelPlugin\Providers\SchemaStateProvider::setSchema(
            new \Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator(),
        );

        $type = ModelPropertyHandler::resolveColumnType($this->codebase, WorkOrder::class, 'unknown_column');

        $this->assertNull($type);
    }
}
