<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\Test;

final class ForeignIdForTest extends AbstractSchemaAggregatorTestCase
{
    #[Test]
    public function foreign_id_for_with_custom_column_name(): void
    {
        $schemaAggregator = $this->instantiateSchemaAggregator(
            __DIR__ . '/migrations/foreign_id_for',
        );

        $this->assertArrayHasKey('posts', $schemaAggregator->tables);
        $table = $schemaAggregator->tables['posts'];

        // foreignIdFor(User::class, 'author_id') should use 'author_id'
        self::assertTableHasColumn('author_id', $table);
        self::assertColumnHasType('int', $table->columns['author_id']);
        $this->assertTrue($table->columns['author_id']->unsigned, 'foreignIdFor should produce unsigned column');

        // The old bug would have created a column named 'id' instead
        // With the fix, the only 'id' column is from $table->id()
        $this->assertSame('id', $table->columns['id']->name);
    }
}
