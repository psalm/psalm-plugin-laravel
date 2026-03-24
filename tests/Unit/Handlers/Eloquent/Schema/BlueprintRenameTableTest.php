<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;

#[CoversClass(SchemaAggregator::class)]
final class BlueprintRenameTableTest extends AbstractSchemaAggregatorTestCase
{
    #[Test]
    public function rename_table_via_blueprint_method(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('old_name', static function (Blueprint $table) {
                        $table->id();
                        $table->string('title');
                    });

                    Schema::table('old_name', static function (Blueprint $table) {
                        $table->rename('new_name');
                    });
                }
            };
            PHP);

        $this->assertArrayNotHasKey('old_name', $schema->tables);
        $this->assertArrayHasKey('new_name', $schema->tables);
        $this->assertTableHasNotNullableColumnOfType('title', 'string', $schema->tables['new_name']);
    }

    #[Test]
    public function rename_column_still_works(): void
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
                        $table->string('title');
                    });

                    Schema::table('posts', static function (Blueprint $table) {
                        $table->renameColumn('title', 'name');
                    });
                }
            };
            PHP);

        $table = $schema->tables['posts'];
        $this->assertArrayNotHasKey('title', $table->columns);
        $this->assertTableHasNotNullableColumnOfType('name', 'string', $table);
    }

    /**
     * When $table->rename('new') targets a table that already exists, skip
     * the rename — the conditional branch wouldn't have executed at runtime.
     *
     * @see https://github.com/psalm/psalm-plugin-laravel/issues/513
     */
    #[Test]
    public function blueprint_rename_to_existing_table_preserves_target(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('new_name', static function (Blueprint $table) {
                        $table->id();
                        $table->string('title');
                        $table->string('body');
                    });

                    // Simulates a conditional rename migration that the aggregator
                    // flattens — target already exists, so this should be skipped
                    Schema::table('old_name', static function (Blueprint $table) {
                        $table->integer('legacy_id');
                        $table->rename('new_name');
                    });
                }
            };
            PHP);

        $table = $schema->tables['new_name'];
        // Original columns from Schema::create() are preserved
        $this->assertTableHasNotNullableColumnOfType('title', 'string', $table);
        $this->assertTableHasNotNullableColumnOfType('body', 'string', $table);
        // The old table is removed
        $this->assertArrayNotHasKey('old_name', $schema->tables);
    }
}
