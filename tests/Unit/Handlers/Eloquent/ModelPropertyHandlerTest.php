<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent;

use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Attributes\Table;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ColumnInfo;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistryBuilder;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelPropertyHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelRegistrationHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaStateProvider;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaTable;
use Psalm\Plugin\EventHandler\Event\PropertyExistenceProviderEvent;
use Psalm\Progress\VoidProgress;
use Psalm\StatementsSource;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models\AttributeConfiguredModel;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/446
 *
 * Property existence now resolves through {@see \Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistry}
 * (Phase 1 of the registry migration), so setUp() warms the model before asserting — the handler
 * no longer reads the schema lazily on its own. The SQL-type → Psalm-type mapper stays in the
 * handler and is exercised directly against {@see ColumnInfo}.
 */
#[CoversClass(ModelPropertyHandler::class)]
#[CoversClass(ModelRegistrationHandler::class)]
final class ModelPropertyHandlerTest extends TestCase
{
    private ClassLikeStorageProvider $classLikeStorageProvider;

    private Codebase $codebase;

    #[\Override]
    protected function setUp(): void
    {
        $schema = new SchemaAggregator();
        $table = new SchemaTable();
        $table->setColumn(new SchemaColumn('id', SchemaColumn::TYPE_INT));
        $table->setColumn(new SchemaColumn('title', SchemaColumn::TYPE_STRING));
        $table->setColumn(new SchemaColumn('published_at', SchemaColumn::TYPE_STRING, nullable: true));
        $table->setColumn(new SchemaColumn(
            'status',
            SchemaColumn::TYPE_SET,
            options: ['draft', 'published', 'archived'],
        ));
        $schema->tables['work_orders'] = $table;
        SchemaStateProvider::setSchema($schema);

        $this->classLikeStorageProvider = new ClassLikeStorageProvider();
        $this->classLikeStorageProvider->create(WorkOrder::class);

        $this->codebase = $this->makeCodebase();

        // Property existence/type now reads the warm registry, so seed it for WorkOrder.
        ModelMetadataRegistryBuilder::reset();
        ModelMetadataRegistryBuilder::warmUp($this->codebase, WorkOrder::class);
    }

    #[\Override]
    protected function tearDown(): void
    {
        ModelMetadataRegistryBuilder::reset();
        $this->classLikeStorageProvider->remove(WorkOrder::class);
    }

    #[Test]
    public function it_recognizes_column_property_in_read_mode(): void
    {
        $event = $this->createEvent(WorkOrder::class, 'title', readMode: true);

        $this->assertTrue(ModelPropertyHandler::doesPropertyExist($event));
    }

    #[Test]
    public function it_returns_null_for_write_mode(): void
    {
        $event = $this->createEvent(WorkOrder::class, 'title', readMode: false);

        $this->assertNull(
            ModelPropertyHandler::doesPropertyExist($event),
            'Write mode is handled via pseudo_property_set_types, not doesPropertyExist()',
        );
    }

    #[Test]
    public function it_returns_null_for_unknown_property(): void
    {
        $event = $this->createEvent(WorkOrder::class, 'nonexistent', readMode: true);

        $this->assertNull(ModelPropertyHandler::doesPropertyExist($event));
    }

    #[Test]
    public function it_resolves_all_columns_for_model(): void
    {
        $columns = ModelPropertyHandler::resolveAllColumns(WorkOrder::class);

        $this->assertArrayHasKey('id', $columns);
        $this->assertArrayHasKey('title', $columns);
        $this->assertArrayHasKey('published_at', $columns);
        $this->assertArrayHasKey('status', $columns);
        $this->assertCount(4, $columns);
    }

    #[Test]
    public function table_attribute_columns_are_registered_for_property_writes(): void
    {
        if (!\class_exists(Table::class)) {
            self::markTestSkipped('Eloquent PHP class attributes require Laravel >= 13.0.');
        }

        $schema = new SchemaAggregator();
        $table = new SchemaTable();
        $table->setColumn(new SchemaColumn('flagged', SchemaColumn::TYPE_BOOL));

        $schema->setTable('attr_table', $table);
        SchemaStateProvider::setSchema($schema);

        $storage = $this->classLikeStorageProvider->create(AttributeConfiguredModel::class);
        ModelMetadataRegistryBuilder::warmUp($this->codebase, AttributeConfiguredModel::class);

        $method = new \ReflectionMethod(ModelRegistrationHandler::class, 'registerWriteTypesForColumns');
        $method->invoke(null, $storage, AttributeConfiguredModel::class);

        $this->assertArrayHasKey(
            '$flagged',
            $storage->pseudo_property_set_types,
            'A migration-backed #[Table] column must be recognized as a writable magic property.',
        );
        $this->assertTrue($storage->pseudo_property_set_types['$flagged']->isMixed());
    }

    /**
     * Regression test for #924: MySQL SET columns previously fell through to `mixed`
     * because the column mapper had no arm for {@see SchemaColumn::TYPE_SET}.
     *
     * MySQL returns SET as a comma-separated string at runtime (e.g. `'draft,published'`),
     * so a literal-union is an over-narrowing approximation, but strictly better than
     * `mixed` for the common `in_array($model->status, [...])` pattern. Mirrors Larastan.
     *
     * Without Psalm Config initialized, {@see \Psalm\Type\Atomic\TLiteralString::make()}
     * falls back to plain `TString` — so we assert against the string type rather than
     * the literals (see {@see \Tests\Psalm\LaravelPlugin\Unit\Handlers\Validation\ValidationRuleAnalyzerTest::in_rule_returns_string_type}
     * for the same pattern). The literal narrowing is exercised end-to-end in integration runs.
     */
    #[Test]
    public function it_narrows_set_column_away_from_mixed(): void
    {
        $union = $this->mapColumn(SchemaColumn::TYPE_SET, options: ['draft', 'published', 'archived']);

        $this->assertFalse($union->isMixed(), 'SET column must not be inferred as `mixed`');
        $this->assertTrue($union->hasString(), 'SET column should map to a string-flavored union');
        $this->assertFalse($union->isNullable(), 'Non-nullable SET column must not include null');
    }

    #[Test]
    public function it_includes_null_for_nullable_set_column(): void
    {
        $union = $this->mapColumn(SchemaColumn::TYPE_SET, nullable: true, options: ['draft', 'published']);

        $this->assertFalse($union->isMixed());
        $this->assertTrue($union->isNullable(), 'Nullable SET column must include null');
    }

    /**
     * Edge case: a SET column whose options never get parsed (rare — e.g. a malformed
     * migration or {@see \Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SqlSchemaParser}
     * meeting `SET` without an option list). Should degrade to plain string, never `mixed`.
     */
    #[Test]
    public function it_falls_back_to_string_for_set_column_without_options(): void
    {
        $union = $this->mapColumn(SchemaColumn::TYPE_SET, options: []);

        $this->assertFalse($union->isMixed());
        $this->assertTrue($union->hasString());
    }

    #[Test]
    public function it_returns_empty_for_unknown_model(): void
    {
        $this->assertSame([], ModelPropertyHandler::resolveAllColumns('NonExistent\\Model'));
    }

    /**
     * Invoke the private SQL-type → Psalm-type mapper (which applies nullability) against a
     * {@see ColumnInfo}, the value object the registry now hands the handler.
     *
     * @param list<string> $options
     */
    private function mapColumn(string $sqlType, bool $nullable = false, array $options = []): \Psalm\Type\Union
    {
        $column = new ColumnInfo('status', $sqlType, $nullable, hasDefault: false, options: $options);
        $method = new \ReflectionMethod(ModelPropertyHandler::class, 'mapColumnType');

        return $method->invoke(null, $column);
    }

    private function makeCodebase(): Codebase
    {
        $codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();
        $codebase->classlike_storage_provider = $this->classLikeStorageProvider;

        // $progress is declared protected(set) readonly in Psalm 7 — bypass via reflection.
        $progressProperty = new \ReflectionProperty(Codebase::class, 'progress');
        $progressProperty->setValue($codebase, new VoidProgress());

        return $codebase;
    }

    private function createEvent(string $className, string $propertyName, bool $readMode): PropertyExistenceProviderEvent
    {
        $source = $this->createStub(StatementsSource::class);
        $source->method('getCodebase')->willReturn($this->codebase);

        return new PropertyExistenceProviderEvent(
            $className,
            $propertyName,
            $readMode,
            $source,
        );
    }
}
