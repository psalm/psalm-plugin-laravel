<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;

/**
 * Tests that Schema::table() columns are correctly merged when create and
 * alter migrations are in separate files, and that file ordering matters.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/513
 */
#[CoversClass(SchemaAggregator::class)]
final class MultiFileMigrationTest extends AbstractSchemaAggregatorTestCase
{
    #[Test]
    public function schema_table_in_separate_files_adds_columns_to_existing_tables(): void
    {
        $schema = $this->instantiateSchemaAggregator(__DIR__ . '/migrations/multi_file_schema_table');

        // contact_sort_order added via Schema::table('users') in a later migration
        $this->assertTableHasNotNullableColumnOfType('contact_sort_order', 'string', $schema->tables['users']);
        $this->assertColumnHasDefault('last_updated', $schema->tables['users']->columns['contact_sort_order']);

        // slice_of_life_id added to posts via Schema::table() in a migration
        // that also does Schema::create('slice_of_lives')
        $this->assertTableHasNullableColumnOfType('slice_of_life_id', 'int', $schema->tables['posts']);
        $this->assertArrayHasKey('slice_of_lives', $schema->tables);

        // can_be_deleted added via Schema::table('templates') in a later migration
        $this->assertTableHasNotNullableColumnOfType('can_be_deleted', 'bool', $schema->tables['templates']);
    }

    /**
     * Documents why migration file ordering matters: when Schema::table()
     * runs before Schema::create(), the create replaces the auto-created
     * table, losing columns added by Schema::table().
     */
    #[Test]
    public function wrong_order_schema_table_before_create_loses_alter_columns(): void
    {
        $schema = new SchemaAggregator();

        // Process the alter migration FIRST (wrong order)
        $this->addMigrationStatements($schema, <<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::table('users', function (Blueprint $table) {
                        $table->string('contact_sort_order');
                    });
                }
            };
            PHP);

        // Then process the create migration (should have been first)
        $this->addMigrationStatements($schema, <<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('users', function (Blueprint $table) {
                        $table->id();
                        $table->string('name');
                    });
                }
            };
            PHP);

        // Schema::create() replaces the table — this is the bug that sorting prevents
        $this->assertTableHasNotNullableColumnOfType('name', 'string', $schema->tables['users']);
        $this->assertArrayNotHasKey('contact_sort_order', $schema->tables['users']->columns);
    }

    /**
     * Schema::rename() to an existing table must skip the rename — the
     * conditional branch wouldn't have executed at runtime. Real case:
     * monicahq/monica renames 'synctokens' → 'sync_tokens' conditionally.
     */
    #[Test]
    public function rename_to_existing_table_preserves_target(): void
    {
        $schema = new SchemaAggregator();

        $this->addMigrationStatements($schema, <<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('sync_tokens', function (Blueprint $table) {
                        $table->id();
                        $table->string('name');
                        $table->timestamp('timestamp');
                    });
                }
            };
            PHP);

        $this->addMigrationStatements($schema, <<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::table('synctokens', function (Blueprint $table) {
                        $table->unsignedBigInteger('id');
                    });
                    Schema::rename('synctokens', 'sync_tokens');
                }
            };
            PHP);

        $table = $schema->tables['sync_tokens'];
        $this->assertTableHasNotNullableColumnOfType('name', 'string', $table);
        $this->assertTableHasNotNullableColumnOfType('timestamp', 'string', $table);
        $this->assertArrayNotHasKey('synctokens', $schema->tables);
    }
}
