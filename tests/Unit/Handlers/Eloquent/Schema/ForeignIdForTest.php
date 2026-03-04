<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn;

/**
 * @covers \Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator
 *
 * Tests foreignIdFor() column name resolution.
 * Previously, foreignIdFor(User::class) hardcoded the column name to 'id'.
 * Now:
 * - With a class reference + custom column: uses the custom column name
 * - With a class reference (no custom): resolves via model's getForeignKey() (requires autoloading)
 * - Produces unsigned int columns (foreignIdFor is always unsigned)
 */
final class ForeignIdForTest extends AbstractSchemaAggregatorTestCase
{
    /** @test */
    public function foreign_id_for_with_custom_column_name(): void
    {
        $schemaAggregator = $this->instantiateSchemaAggregator(
            __DIR__ . '/migrations/foreign_id_for'
        );

        self::assertArrayHasKey('posts', $schemaAggregator->tables);
        $table = $schemaAggregator->tables['posts'];

        // foreignIdFor(User::class, 'author_id') should use 'author_id'
        self::assertTableHasColumn('author_id', $table);
        self::assertColumnHasType('int', $table->columns['author_id']);
        self::assertTrue($table->columns['author_id']->unsigned, 'foreignIdFor should produce unsigned column');

        // The old bug would have created a column named 'id' instead
        // With the fix, the only 'id' column is from $table->id()
        self::assertSame('id', $table->columns['id']->name);
    }
}
