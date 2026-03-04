<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn;

/**
 * @covers \Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator
 * @covers \Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn
 *
 * Tests unsigned integer tracking: methods like unsignedBigInteger, increments,
 * foreignId etc. should set the unsigned flag on SchemaColumn.
 */
final class UnsignedIntegerTest extends AbstractSchemaAggregatorTestCase
{
    /** @test */
    public function unsigned_integer_methods_produce_unsigned_columns(): void
    {
        $schemaAggregator = $this->instantiateSchemaAggregator(
            __DIR__ . '/migrations/unsigned_integers'
        );

        self::assertArrayHasKey('products', $schemaAggregator->tables);

        $table = $schemaAggregator->tables['products'];

        // id() — auto-increment, should be unsigned
        self::assertColumnIsUnsigned($table->columns['id']);

        // Explicitly unsigned methods
        self::assertColumnIsUnsigned($table->columns['category_id']);
        self::assertColumnIsUnsigned($table->columns['stock']);
        self::assertColumnIsUnsigned($table->columns['priority']);
        self::assertColumnIsUnsigned($table->columns['rating']);
        self::assertColumnIsUnsigned($table->columns['views']);

        // foreignId is always unsigned
        self::assertColumnIsUnsigned($table->columns['user_id']);

        // All *increments methods are unsigned
        self::assertColumnIsUnsigned($table->columns['legacy_id']);
        self::assertColumnIsUnsigned($table->columns['big_legacy_id']);
        self::assertColumnIsUnsigned($table->columns['tiny_legacy_id']);
        self::assertColumnIsUnsigned($table->columns['small_legacy_id']);
        self::assertColumnIsUnsigned($table->columns['medium_legacy_id']);
        self::assertColumnIsUnsigned($table->columns['int_legacy_id']);

        // Signed integer methods should NOT be unsigned
        self::assertColumnIsNotUnsigned($table->columns['signed_quantity']);
        self::assertColumnIsNotUnsigned($table->columns['signed_big']);

        // ->unsigned() chained modifier
        self::assertColumnIsUnsigned($table->columns['made_unsigned']);

        // morphs() produces unsigned id column
        self::assertColumnIsUnsigned($table->columns['taggable_id']);
        self::assertColumnIsNotUnsigned($table->columns['taggable_type']);

        // nullableMorphs() produces unsigned id column
        self::assertColumnIsUnsigned($table->columns['imageable_id']);
        self::assertColumnIsNotUnsigned($table->columns['imageable_type']);
    }

    private static function assertColumnIsUnsigned(SchemaColumn $column): void
    {
        self::assertTrue(
            $column->unsigned,
            "Column '{$column->name}' should be unsigned but is not"
        );
        self::assertSame('int', $column->type, "Unsigned column '{$column->name}' should be int type");
    }

    private static function assertColumnIsNotUnsigned(SchemaColumn $column): void
    {
        self::assertFalse(
            $column->unsigned,
            "Column '{$column->name}' should not be unsigned but is"
        );
    }
}
