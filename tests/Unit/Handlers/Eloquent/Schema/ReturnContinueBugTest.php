<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\Test;

final class ReturnContinueBugTest extends AbstractSchemaAggregatorTestCase
{
    #[Test]
    public function columns_after_non_method_call_statements_are_detected(): void
    {
        $schemaAggregator = $this->instantiateSchemaAggregator(
            __DIR__ . '/migrations/return_continue_bug'
        );

        self::assertArrayHasKey('orders', $schemaAggregator->tables);

        $table = $schemaAggregator->tables['orders'];

        // Columns before the if block
        self::assertTableHasNotNullableColumnOfType('id', 'int', $table);
        self::assertTableHasNotNullableColumnOfType('name', 'string', $table);

        // Columns AFTER the if block — these were dropped by the old `return` bug
        self::assertTableHasNotNullableColumnOfType('email', 'string', $table);
        self::assertTableHasNotNullableColumnOfType('total', 'float', $table);

        // Timestamps added after the if block
        self::assertTableHasNullableColumnOfType('created_at', 'string', $table);
        self::assertTableHasNullableColumnOfType('updated_at', 'string', $table);
    }
}
