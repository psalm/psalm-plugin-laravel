<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;

#[CoversClass(SchemaAggregator::class)]
final class SpatialColumnTypesTest extends AbstractSchemaAggregatorTestCase
{
    #[Test]
    public function geography_maps_to_mixed(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('places', static function (Blueprint $table) {
                        $table->id();
                        $table->geography('location');
                    });
                }
            };
            PHP);

        $this->assertTableHasNotNullableColumnOfType('location', 'mixed', $schema->tables['places']);
    }
}
