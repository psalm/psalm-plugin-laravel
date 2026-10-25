<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;

/**
 * Tests that Schema::table() (ALTER TABLE) columns are correctly merged
 * when create and alter migrations are in separate files.
 *
 * The instantiateSchemaAggregator() helper uses glob() which sorts
 * alphabetically, matching Laravel's migrator.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/513
 */
#[CoversClass(SchemaAggregator::class)]
final class MultiFileMigrationTest extends AbstractSchemaAggregatorTestCase
{
    private SchemaAggregator $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema = $this->instantiateSchemaAggregator(__DIR__ . '/migrations/multi_file_schema_table');
    }

    #[Test]
    public function schema_table_in_separate_file_adds_column_to_existing_table(): void
    {
        // contact_sort_order is added via Schema::table('users') in a later migration
        $table = $this->schema->tables['users'];

        $this->assertTableHasNotNullableColumnOfType('id', 'int', $table);
        $this->assertTableHasNotNullableColumnOfType('name', 'string', $table);
        $this->assertTableHasNotNullableColumnOfType('email', 'string', $table);
        $this->assertTableHasNotNullableColumnOfType('contact_sort_order', 'string', $table);
        $this->assertColumnHasDefault('last_updated', $table->columns['contact_sort_order']);
    }

    #[Test]
    public function schema_table_in_migration_that_also_creates_another_table(): void
    {
        // slice_of_life_id is added to posts via Schema::table() in a migration
        // that also does Schema::create('slice_of_lives')
        $posts = $this->schema->tables['posts'];

        $this->assertTableHasNotNullableColumnOfType('id', 'int', $posts);
        $this->assertTableHasNotNullableColumnOfType('title', 'string', $posts);
        $this->assertTableHasNullableColumnOfType('slice_of_life_id', 'int', $posts);

        // The other table created in the same migration should also exist
        $this->assertArrayHasKey('slice_of_lives', $this->schema->tables);
        $this->assertTableHasNotNullableColumnOfType('name', 'string', $this->schema->tables['slice_of_lives']);
    }
}
