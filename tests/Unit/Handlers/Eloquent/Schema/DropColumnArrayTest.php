<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\Test;

final class DropColumnArrayTest extends AbstractSchemaAggregatorTestCase
{
    #[Test]
    public function drop_column_with_array_argument_removes_all_listed_columns(): void
    {
        $schemaAggregator = $this->instantiateSchemaAggregator(
            __DIR__ . '/migrations/drop_column_array',
        );

        self::assertArrayHasKey('posts', $schemaAggregator->tables);

        $table = $schemaAggregator->tables['posts'];

        // These columns should still exist
        self::assertTableHasNotNullableColumnOfType('id', 'int', $table);
        self::assertTableHasNotNullableColumnOfType('title', 'string', $table);
        self::assertTableHasNotNullableColumnOfType('body', 'string', $table);
        self::assertTableHasNullableColumnOfType('created_at', 'string', $table);
        self::assertTableHasNullableColumnOfType('updated_at', 'string', $table);

        // These columns should have been dropped by Blueprint::dropColumn(['slug', 'legacy_field'])
        self::assertArrayNotHasKey('slug', $table->columns);
        self::assertArrayNotHasKey('legacy_field', $table->columns);

        // These columns should have been dropped by Schema::dropColumns('posts', ['old_status', 'temp_flag'])
        self::assertArrayNotHasKey('old_status', $table->columns);
        self::assertArrayNotHasKey('temp_flag', $table->columns);
    }
}
