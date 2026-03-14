<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;

#[CoversClass(SchemaAggregator::class)]
final class RawColumnTest extends AbstractSchemaAggregatorTestCase
{
    #[Test]
    public function raw_column_maps_to_mixed(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('events', static function (Blueprint $table) {
                        $table->id();
                        $table->rawColumn('duration', 'INTERVAL');
                    });
                }
            };
            PHP);

        $this->assertTableHasNotNullableColumnOfType('duration', 'mixed', $schema->tables['events']);
    }
}
