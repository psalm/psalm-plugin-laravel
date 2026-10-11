<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent;

use App\Models\WorkOrder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\Internal\Codebase\ClassLikes;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistryBuilder;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelAggregatePropertyHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelPropertyHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaStateProvider;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaTable;
use Psalm\Progress\VoidProgress;
use Psalm\Type;
use Psalm\Type\Atomic\TIntRange;
use Psalm\Type\Union;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\ArrayFormCastsModel;

#[CoversClass(ModelAggregatePropertyHandler::class)]
final class ModelAggregatePropertyHandlerTest extends TestCase
{
    // -------------------------------------------------------------------------
    // couldBeAggregate()
    // -------------------------------------------------------------------------
    /** @return \Iterator<string, array{string, bool}> */
    public static function couldBeAggregateProvider(): \Iterator
    {
        // exact suffixes
        yield 'contacts_count' => ['contacts_count', true];
        yield 'contacts_exists' => ['contacts_exists', true];
        // column-including suffixes
        yield 'contacts_sum_amount' => ['contacts_sum_amount', true];
        yield 'contacts_avg_price' => ['contacts_avg_price', true];
        yield 'contacts_min_created_at' => ['contacts_min_created_at', true];
        yield 'contacts_max_updated_at' => ['contacts_max_updated_at', true];
        // non-aggregate properties
        yield 'email' => ['email', false];
        yield 'name' => ['name', false];
        yield 'created_at' => ['created_at', false];
        // partial match — suffix without underscore prefix
        yield 'count' => ['count', false];
        yield 'sum_amount' => ['sum_amount', false];
        // suffix in wrong position
        yield 'count_contacts' => ['count_contacts', false];
    }

    #[Test]
    #[DataProvider('couldBeAggregateProvider')]
    public function couldBeAggregate_returns_expected(string $property, bool $expected): void
    {
        $method = new \ReflectionMethod(ModelAggregatePropertyHandler::class, 'couldBeAggregate');

        $this->assertSame($expected, $method->invoke(null, $property));
    }

    // -------------------------------------------------------------------------
    // snakeToCamelCase()
    // -------------------------------------------------------------------------
    /** @return \Iterator<string, array{string, string}> */
    public static function snakeToCamelCaseProvider(): \Iterator
    {
        yield 'single word' => ['contacts', 'contacts'];
        yield 'two words' => ['work_orders', 'workOrders'];
        yield 'three words' => ['work_order_items', 'workOrderItems'];
        yield 'already camelCase' => ['workOrders', 'workOrders'];
        yield 'leading underscore' => ['_contacts', 'contacts'];
    }

    #[Test]
    #[DataProvider('snakeToCamelCaseProvider')]
    public function snakeToCamelCase_converts_correctly(string $input, string $expected): void
    {
        $method = new \ReflectionMethod(ModelAggregatePropertyHandler::class, 'snakeToCamelCase');

        $this->assertSame($expected, $method->invoke(null, $input));
    }

    // -------------------------------------------------------------------------
    // columnAwareType(): min/max/sum/avg cells (#1623)
    // -------------------------------------------------------------------------
    /** @return \Iterator<string, array{'sum'|'min'|'max'|'avg', ?Union, string}> */
    public static function columnAwareTypeProvider(): \Iterator
    {
        $unsignedInt = new Union([new TIntRange(0, null)]);

        yield 'min int' => ['min', Type::getInt(), 'int|null'];
        yield 'max unsigned int' => ['max', $unsignedInt, 'int<0, max>|null'];
        // The schema maps DECIMAL to float; PDO returns it as a string, and SQLite as int when integral.
        yield 'min float' => ['min', Type::getFloat(), 'float|int|null|numeric-string'];
        yield 'max string (datetime, varchar)' => ['max', Type::getString(), 'null|string'];
        yield 'min bool column keeps the fallback' => ['min', Type::getBool(), 'null|string'];
        yield 'max unresolvable' => ['max', null, 'null|string'];

        yield 'sum int' => ['sum', Type::getInt(), 'int|null|numeric-string'];
        yield 'sum float' => ['sum', Type::getFloat(), 'float|int|null|numeric-string'];
        yield 'sum string column' => ['sum', Type::getString(), 'float|int|null|numeric-string'];
        yield 'sum unresolvable' => ['sum', null, 'float|int|null|numeric-string'];

        yield 'avg int' => ['avg', Type::getInt(), 'float|null|numeric-string'];
        yield 'avg float' => ['avg', Type::getFloat(), 'float|null|numeric-string'];
        yield 'avg unresolvable' => ['avg', null, 'float|null|numeric-string'];
    }

    /** @param 'sum'|'min'|'max'|'avg' $function */
    #[Test]
    #[DataProvider('columnAwareTypeProvider')]
    public function columnAwareType_maps_raw_column_type(string $function, ?Union $raw, string $expected): void
    {
        $this->assertSame($expected, ModelAggregatePropertyHandler::columnAwareType($function, $raw)->getId());
    }

    #[Test]
    public function raw_column_type_is_schema_only_and_unknown_columns_are_unresolvable(): void
    {
        $classLikeStorageProvider = new ClassLikeStorageProvider();
        $classLikeStorageProvider->create(WorkOrder::class);

        $codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();
        $codebase->classlike_storage_provider = $classLikeStorageProvider;
        $codebase->classlikes = (new \ReflectionClass(ClassLikes::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Codebase::class, 'progress'))->setValue($codebase, new VoidProgress());

        $table = new SchemaTable();
        $table->setColumn(new SchemaColumn('price', SchemaColumn::TYPE_FLOAT));
        $table->setColumn(new SchemaColumn('created_at', SchemaColumn::TYPE_STRING, nullable: true));

        $schema = new SchemaAggregator();
        $schema->tables['work_orders'] = $table;
        SchemaStateProvider::setSchema($schema);

        ModelMetadataRegistryBuilder::reset();
        ModelMetadataRegistryBuilder::warmUp($codebase, WorkOrder::class);

        try {
            $this->assertSame('float', (string) ModelPropertyHandler::resolveRawColumnType(WorkOrder::class, 'price'));
            // Nullability is dropped: the aggregate is nullable anyway (empty relation).
            $this->assertSame('string', (string) ModelPropertyHandler::resolveRawColumnType(WorkOrder::class, 'created_at'));
            $this->assertNotInstanceOf(Union::class, ModelPropertyHandler::resolveRawColumnType(WorkOrder::class, 'missing'));
        } finally {
            ModelMetadataRegistryBuilder::reset();
            SchemaStateProvider::setSchema(new SchemaAggregator());
            $classLikeStorageProvider->remove(WorkOrder::class);
        }
    }

    #[Test]
    public function real_schema_columns_and_cast_keys_are_real_attributes_but_other_names_are_not(): void
    {
        $classLikeStorageProvider = new ClassLikeStorageProvider();
        $classLikeStorageProvider->create(WorkOrder::class);
        $classLikeStorageProvider->create(ArrayFormCastsModel::class);

        $codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();
        $codebase->classlike_storage_provider = $classLikeStorageProvider;
        $codebase->classlikes = (new \ReflectionClass(ClassLikes::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Codebase::class, 'progress'))->setValue($codebase, new VoidProgress());

        $table = new SchemaTable();
        $table->setColumn(new SchemaColumn('parts_count', SchemaColumn::TYPE_INT));

        $schema = new SchemaAggregator();
        $schema->tables['work_orders'] = $table;
        SchemaStateProvider::setSchema($schema);

        ModelMetadataRegistryBuilder::reset();
        ModelMetadataRegistryBuilder::warmUp($codebase, WorkOrder::class);
        ModelMetadataRegistryBuilder::warmUp($codebase, ArrayFormCastsModel::class);

        $isRealAttribute = new \ReflectionMethod(ModelAggregatePropertyHandler::class, 'isRealAttribute');

        try {
            // Pixelfed shape: `votes_count` is a migration column next to a `votes()` relation.
            $this->assertTrue($isRealAttribute->invoke(null, WorkOrder::class, 'parts_count'));
            $this->assertFalse($isRealAttribute->invoke(null, WorkOrder::class, 'vehicle_count'));
            $this->assertTrue($isRealAttribute->invoke(null, ArrayFormCastsModel::class, 'plain_tags'));
            $this->assertFalse($isRealAttribute->invoke(null, ArrayFormCastsModel::class, 'options_count'));
            // Not warmed up: nothing is proven, so nothing is declined.
            $this->assertFalse($isRealAttribute->invoke(null, 'App\\Models\\Unwarmed', 'parts_count'));
        } finally {
            ModelMetadataRegistryBuilder::reset();
            SchemaStateProvider::setSchema(new SchemaAggregator());
            $classLikeStorageProvider->remove(WorkOrder::class);
            $classLikeStorageProvider->remove(ArrayFormCastsModel::class);
        }
    }
}
