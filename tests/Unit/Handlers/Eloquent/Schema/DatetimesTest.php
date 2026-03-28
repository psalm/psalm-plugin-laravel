<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;

#[CoversClass(SchemaAggregator::class)]
final class DatetimesTest extends AbstractSchemaAggregatorTestCase
{
    #[Test]
    public function datetimes_creates_nullable_created_at_and_updated_at(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('posts', static function (Blueprint $table) {
                        $table->id();
                        $table->datetimes();
                    });
                }
            };
            PHP);

        $this->assertTableHasNullableColumnOfType('created_at', 'string', $schema->tables['posts']);
        $this->assertTableHasNullableColumnOfType('updated_at', 'string', $schema->tables['posts']);
    }

    #[Test]
    public function datetimes_with_precision_creates_nullable_columns(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('posts', static function (Blueprint $table) {
                        $table->id();
                        $table->datetimes(0);
                    });
                }
            };
            PHP);

        $this->assertTableHasNullableColumnOfType('created_at', 'string', $schema->tables['posts']);
        $this->assertTableHasNullableColumnOfType('updated_at', 'string', $schema->tables['posts']);
    }

    #[Test]
    public function datetimes_behaves_like_timestamps(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('posts_a', static function (Blueprint $table) {
                        $table->id();
                        $table->datetimes();
                    });

                    Schema::create('posts_b', static function (Blueprint $table) {
                        $table->id();
                        $table->timestamps();
                    });
                }
            };
            PHP);

        // Both methods should produce identical schema columns
        $this->assertEquals(
            $schema->tables['posts_a']->columns['created_at'],
            $schema->tables['posts_b']->columns['created_at'],
        );
        $this->assertEquals(
            $schema->tables['posts_a']->columns['updated_at'],
            $schema->tables['posts_b']->columns['updated_at'],
        );
    }
}
