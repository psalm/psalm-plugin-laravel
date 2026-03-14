<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;

#[CoversClass(SchemaAggregator::class)]
final class SoftDeletesDatetimeTest extends AbstractSchemaAggregatorTestCase
{
    #[Test]
    public function default_column_name(): void
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
                        $table->softDeletesDatetime();
                    });
                }
            };
            PHP);

        $this->assertTableHasNullableColumnOfType('deleted_at', 'string', $schema->tables['posts']);
    }

    #[Test]
    public function custom_column_name(): void
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
                        $table->softDeletesDatetime('archived_at');
                    });
                }
            };
            PHP);

        $this->assertTableHasNullableColumnOfType('archived_at', 'string', $schema->tables['posts']);
    }

    #[Test]
    public function drop_removes_default_column(): void
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
                        $table->softDeletesDatetime();
                    });

                    Schema::table('posts', static function (Blueprint $table) {
                        $table->dropSoftDeletesDatetime();
                    });
                }
            };
            PHP);

        $this->assertArrayNotHasKey('deleted_at', $schema->tables['posts']->columns);
    }
}
