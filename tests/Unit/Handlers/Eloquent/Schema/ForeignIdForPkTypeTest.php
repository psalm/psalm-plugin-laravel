<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;

#[CoversClass(SchemaAggregator::class)]
final class ForeignIdForPkTypeTest extends AbstractSchemaAggregatorTestCase
{
    #[Test]
    public function foreign_id_for_uuid_model_produces_string_column(): void
    {
        $schemaAggregator = $this->instantiateSchemaAggregator(
            __DIR__ . '/migrations/foreign_id_for_pk_type',
        );

        $table = $schemaAggregator->tables['comments'];

        // foreignIdFor(UuidModel::class) → string because PK is uuid
        self::assertTableHasColumn('uuid_model_id', $table);
        self::assertColumnHasType('string', $table->columns['uuid_model_id']);
        self::assertColumnNotNullable($table->columns['uuid_model_id']);
        $this->assertFalse($table->columns['uuid_model_id']->unsigned, 'UUID FK should not be unsigned');
    }

    #[Test]
    public function foreign_id_for_ulid_model_produces_string_column(): void
    {
        $schemaAggregator = $this->instantiateSchemaAggregator(
            __DIR__ . '/migrations/foreign_id_for_pk_type',
        );

        $table = $schemaAggregator->tables['comments'];

        // foreignIdFor(UlidModel::class) → string because PK is ulid
        self::assertTableHasColumn('ulid_model_id', $table);
        self::assertColumnHasType('string', $table->columns['ulid_model_id']);
        self::assertColumnNotNullable($table->columns['ulid_model_id']);
        $this->assertFalse($table->columns['ulid_model_id']->unsigned, 'ULID FK should not be unsigned');
    }

    #[Test]
    public function foreign_id_for_standard_model_produces_unsigned_int_column(): void
    {
        $schemaAggregator = $this->instantiateSchemaAggregator(
            __DIR__ . '/migrations/foreign_id_for_pk_type',
        );

        $table = $schemaAggregator->tables['comments'];

        // foreignIdFor(Customer::class) → unsigned int because PK is standard auto-increment
        self::assertTableHasColumn('customer_id', $table);
        self::assertColumnHasType('int', $table->columns['customer_id']);
        self::assertColumnNotNullable($table->columns['customer_id']);
        $this->assertTrue($table->columns['customer_id']->unsigned, 'Standard FK should be unsigned');
    }

    #[Test]
    public function foreign_id_for_uuid_model_with_custom_column_name(): void
    {
        $schemaAggregator = $this->instantiateSchemaAggregator(
            __DIR__ . '/migrations/foreign_id_for_pk_type',
        );

        $table = $schemaAggregator->tables['comments'];

        // foreignIdFor(UuidModel::class, 'reviewer_id') → string with custom name
        self::assertTableHasColumn('reviewer_id', $table);
        self::assertColumnHasType('string', $table->columns['reviewer_id']);
        self::assertColumnNotNullable($table->columns['reviewer_id']);
        $this->assertFalse($table->columns['reviewer_id']->unsigned, 'UUID FK with custom name should not be unsigned');
    }

    #[Test]
    public function foreign_id_for_uuid_model_nullable(): void
    {
        $schemaAggregator = $this->instantiateSchemaAggregator(
            __DIR__ . '/migrations/foreign_id_for_pk_type',
        );

        $table = $schemaAggregator->tables['comments'];

        // foreignIdFor(UuidModel::class, 'editor_id')->nullable() → nullable string
        self::assertTableHasColumn('editor_id', $table);
        self::assertColumnHasType('string', $table->columns['editor_id']);
        self::assertColumnNullable($table->columns['editor_id']);
        $this->assertFalse($table->columns['editor_id']->unsigned);
    }

    #[Test]
    public function foreign_id_for_model_with_custom_primary_key(): void
    {
        $schemaAggregator = $this->instantiateSchemaAggregator(
            __DIR__ . '/migrations/foreign_id_for_pk_type',
        );

        $table = $schemaAggregator->tables['comments'];

        // foreignIdFor(CustomPkUuidModel::class) → string because custom PK 'custom_pk' is uuid
        // getForeignKey() returns 'custom_pk_uuid_model_custom_pk' (class_snake + '_' + key_name)
        self::assertTableHasColumn('custom_pk_uuid_model_custom_pk', $table);
        self::assertColumnHasType('string', $table->columns['custom_pk_uuid_model_custom_pk']);
        self::assertColumnNotNullable($table->columns['custom_pk_uuid_model_custom_pk']);
        $this->assertFalse($table->columns['custom_pk_uuid_model_custom_pk']->unsigned);
    }

    #[Test]
    public function foreign_id_for_falls_back_to_int_when_referenced_table_not_yet_parsed(): void
    {
        // The migration references UuidModel::class but the uuid_models table
        // is not created in this migration set — simulating migration ordering
        // where the FK table is parsed before the referenced table.
        $schemaAggregator = $this->instantiateSchemaAggregator(
            __DIR__ . '/migrations/foreign_id_for_missing_table',
        );

        $table = $schemaAggregator->tables['reviews'];

        // Falls back to unsigned int because the PK type cannot be determined
        self::assertTableHasColumn('uuid_model_id', $table);
        self::assertColumnHasType('int', $table->columns['uuid_model_id']);
        self::assertColumnNotNullable($table->columns['uuid_model_id']);
        $this->assertTrue($table->columns['uuid_model_id']->unsigned);
    }
}
