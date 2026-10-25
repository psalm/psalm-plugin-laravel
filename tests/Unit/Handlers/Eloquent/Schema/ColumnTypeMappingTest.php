<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;

/**
 * Blueprint methods whose column type or default name is not obvious from the method name.
 */
#[CoversClass(SchemaAggregator::class)]
final class ColumnTypeMappingTest extends AbstractSchemaAggregatorTestCase
{
    #[Test]
    public function special_blueprint_methods_map_to_the_expected_column_types(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('items', static function (Blueprint $table) {
                        $table->id();
                        $table->rawColumn('duration', 'INTERVAL');
                        $table->geography('location');
                        $table->vector('embedding', dimensions: 1536);
                        $table->tsvector('searchable');
                        $table->ulid();
                        $table->ulid('custom_ulid');
                        $table->uuid();
                    });
                }
            };
            PHP);

        $table = $schema->tables['items'];

        $this->assertTableHasNotNullableColumnOfType('duration', 'mixed', $table);
        $this->assertTableHasNotNullableColumnOfType('location', 'mixed', $table);
        $this->assertTableHasNotNullableColumnOfType('embedding', 'array', $table);
        $this->assertTableHasNotNullableColumnOfType('searchable', 'string', $table);
        // ulid() without arguments defaults to a column named `ulid`, like uuid() does for `uuid`.
        $this->assertTableHasNotNullableColumnOfType('ulid', 'string', $table);
        $this->assertTableHasNotNullableColumnOfType('custom_ulid', 'string', $table);
        $this->assertTableHasNotNullableColumnOfType('uuid', 'string', $table);
    }
}
