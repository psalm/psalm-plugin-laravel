<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;

#[CoversClass(SchemaAggregator::class)]
final class VectorColumnTest extends AbstractSchemaAggregatorTestCase
{
    #[Test]
    public function vector_maps_to_array(): void
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
                        $table->vector('embedding', dimensions: 1536);
                    });
                }
            };
            PHP);

        $this->assertTableHasNotNullableColumnOfType('embedding', 'array', $schema->tables['items']);
    }

    #[Test]
    public function tsvector_maps_to_string(): void
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
                        $table->tsvector('searchable');
                    });
                }
            };
            PHP);

        $this->assertTableHasNotNullableColumnOfType('searchable', 'string', $schema->tables['items']);
    }
}
