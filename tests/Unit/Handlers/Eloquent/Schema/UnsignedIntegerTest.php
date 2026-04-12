<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn;

#[CoversClass(SchemaColumn::class)]
final class UnsignedIntegerTest extends AbstractSchemaAggregatorTestCase
{
    #[Test]
    public function unsigned_integer_methods_produce_unsigned_columns(): void
    {
        $schemaAggregator = $this->instantiateSchemaAggregator(
            __DIR__ . '/migrations/unsigned_integers',
        );

        $this->assertArrayHasKey('products', $schemaAggregator->tables);

        $table = $schemaAggregator->tables['products'];

        // id() — auto-increment, should be unsigned
        $this->assertColumnIsUnsigned($table->columns['id']);

        // Explicitly unsigned methods
        $this->assertColumnIsUnsigned($table->columns['category_id']);
        $this->assertColumnIsUnsigned($table->columns['stock']);
        $this->assertColumnIsUnsigned($table->columns['priority']);
        $this->assertColumnIsUnsigned($table->columns['rating']);
        $this->assertColumnIsUnsigned($table->columns['views']);

        // foreignId is always unsigned
        $this->assertColumnIsUnsigned($table->columns['user_id']);

        // All *increments methods are unsigned
        $this->assertColumnIsUnsigned($table->columns['legacy_id']);
        $this->assertColumnIsUnsigned($table->columns['big_legacy_id']);
        $this->assertColumnIsUnsigned($table->columns['tiny_legacy_id']);
        $this->assertColumnIsUnsigned($table->columns['small_legacy_id']);
        $this->assertColumnIsUnsigned($table->columns['medium_legacy_id']);
        $this->assertColumnIsUnsigned($table->columns['int_legacy_id']);

        // Signed integer methods should NOT be unsigned
        $this->assertColumnIsNotUnsigned($table->columns['signed_quantity']);
        $this->assertColumnIsNotUnsigned($table->columns['signed_big']);

        // ->unsigned() chained modifier
        $this->assertColumnIsUnsigned($table->columns['made_unsigned']);

        // morphs() produces unsigned id column
        $this->assertColumnIsUnsigned($table->columns['taggable_id']);
        $this->assertColumnIsNotUnsigned($table->columns['taggable_type']);

        // nullableMorphs() produces unsigned id column
        $this->assertColumnIsUnsigned($table->columns['imageable_id']);
        $this->assertColumnIsNotUnsigned($table->columns['imageable_type']);
    }

    private function assertColumnIsUnsigned(SchemaColumn $column): void
    {
        $this->assertTrue($column->unsigned, "Column '{$column->name}' should be unsigned but is not");
        $this->assertSame('int', $column->type, "Unsigned column '{$column->name}' should be int type");
    }

    private function assertColumnIsNotUnsigned(SchemaColumn $column): void
    {
        $this->assertFalse($column->unsigned, "Column '{$column->name}' should not be unsigned but is");
    }
}
