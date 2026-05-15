<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent;

use App\Models\WorkOrder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelPropertyHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaTable;
use Psalm\LaravelPlugin\Providers\SchemaStateProvider;
use Psalm\Plugin\EventHandler\Event\PropertyExistenceProviderEvent;
use Psalm\StatementsSource;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/446
 */
#[CoversClass(ModelPropertyHandler::class)]
final class ModelPropertyHandlerTest extends TestCase
{
    private ClassLikeStorageProvider $classLikeStorageProvider;

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
    }

    #[\Override]
    protected function tearDown(): void
    {
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

    /**
     * Regression test for #924: MySQL SET columns previously fell through to `mixed`
     * because `mapColumnType()` had no arm for {@see SchemaColumn::TYPE_SET}.
     *
     * MySQL returns SET as a comma-separated string at runtime (e.g. `'draft,published'`),
     * so a literal-union is an over-narrowing approximation, but strictly better than
     * `mixed` for the common `in_array($column, [...])` pattern. Mirrors Larastan.
     *
     * Without Psalm Config initialized, {@see \Psalm\Type\Atomic\TLiteralString::make()}
     * falls back to plain `TString` — so we assert against the string type rather than
     * the literals (see {@see \Tests\Psalm\LaravelPlugin\Unit\Handlers\Validation\ValidationRuleAnalyzerTest::in_rule_returns_string_type}
     * for the same pattern). The literal narrowing is exercised end-to-end in integration runs.
     */
    #[Test]
    public function it_narrows_set_column_away_from_mixed(): void
    {
        $column = ModelPropertyHandler::resolveAllColumns(WorkOrder::class)['status'];
        $union = $this->invokeMapColumnType($column);

        $this->assertFalse($union->isMixed(), 'SET column must not be inferred as `mixed`');
        $this->assertTrue($union->hasString(), 'SET column should map to a string-flavored union');
        $this->assertFalse($union->isNullable(), 'Non-nullable SET column must not include null');
    }

    #[Test]
    public function it_includes_null_for_nullable_set_column(): void
    {
        $column = new SchemaColumn(
            'status',
            SchemaColumn::TYPE_SET,
            nullable: true,
            options: ['draft', 'published'],
        );

        $union = $this->invokeMapColumnType($column);

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
        $column = new SchemaColumn('status', SchemaColumn::TYPE_SET, options: []);

        $union = $this->invokeMapColumnType($column);

        $this->assertFalse($union->isMixed());
        $this->assertTrue($union->hasString());
    }

    private function invokeMapColumnType(SchemaColumn $column): \Psalm\Type\Union
    {
        $method = new \ReflectionMethod(ModelPropertyHandler::class, 'mapColumnType');

        return $method->invoke(null, $column);
    }

    #[Test]
    public function it_returns_empty_for_unknown_model(): void
    {
        $this->assertSame([], ModelPropertyHandler::resolveAllColumns('NonExistent\\Model'));
    }

    private function createEvent(string $className, string $propertyName, bool $readMode): PropertyExistenceProviderEvent
    {
        $codebase = (new \ReflectionClass(\Psalm\Codebase::class))->newInstanceWithoutConstructor();
        $codebase->classlike_storage_provider = $this->classLikeStorageProvider;

        $source = $this->createStub(StatementsSource::class);
        $source->method('getCodebase')->willReturn($codebase);

        return new PropertyExistenceProviderEvent(
            $className,
            $propertyName,
            $readMode,
            $source,
        );
    }
}
