<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psalm\Internal\Provider\StatementsProvider;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;

/**
 * Tests that Schema::table() (ALTER TABLE) columns are correctly merged
 * when create and alter migrations are in separate files.
 *
 * The instantiateSchemaAggregator() helper uses glob() which sorts
 * alphabetically, matching Laravel's migrator. The "wrong order" tests
 * below feed files manually in reverse order to prove why sorting matters.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/513
 */
#[CoversClass(SchemaAggregator::class)]
final class MultiFileMigrationTest extends AbstractSchemaAggregatorTestCase
{
    private SchemaAggregator $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schema = $this->instantiateSchemaAggregator(__DIR__ . '/migrations/multi_file_schema_table');
    }

    #[Test]
    public function schema_table_in_separate_file_adds_column_to_existing_table(): void
    {
        // contact_sort_order is added via Schema::table('users') in a later migration
        $table = $this->schema->tables['users'];

        $this->assertTableHasNotNullableColumnOfType('id', 'int', $table);
        $this->assertTableHasNotNullableColumnOfType('name', 'string', $table);
        $this->assertTableHasNotNullableColumnOfType('email', 'string', $table);
        $this->assertTableHasNotNullableColumnOfType('contact_sort_order', 'string', $table);
        $this->assertColumnHasDefault('last_updated', $table->columns['contact_sort_order']);
    }

    #[Test]
    public function schema_table_in_migration_that_also_creates_another_table(): void
    {
        // slice_of_life_id is added to posts via Schema::table() in a migration
        // that also does Schema::create('slice_of_lives')
        $posts = $this->schema->tables['posts'];

        $this->assertTableHasNotNullableColumnOfType('id', 'int', $posts);
        $this->assertTableHasNotNullableColumnOfType('title', 'string', $posts);
        $this->assertTableHasNullableColumnOfType('slice_of_life_id', 'int', $posts);

        // The other table created in the same migration should also exist
        $this->assertArrayHasKey('slice_of_lives', $this->schema->tables);
        $this->assertTableHasNotNullableColumnOfType('name', 'string', $this->schema->tables['slice_of_lives']);
    }

    #[Test]
    public function schema_table_adds_column_to_table_created_in_different_file(): void
    {
        // can_be_deleted is added via Schema::table('templates') in a later migration
        $table = $this->schema->tables['templates'];

        $this->assertTableHasNotNullableColumnOfType('id', 'int', $table);
        $this->assertTableHasNotNullableColumnOfType('name', 'string', $table);
        $this->assertTableHasNotNullableColumnOfType('can_be_deleted', 'bool', $table);
        $this->assertColumnHasDefault(true, $table->columns['can_be_deleted']);
    }

    #[Test]
    public function all_tables_from_multi_file_migrations_are_present(): void
    {
        $this->assertArrayHasKey('users', $this->schema->tables);
        $this->assertArrayHasKey('posts', $this->schema->tables);
        $this->assertArrayHasKey('templates', $this->schema->tables);
        $this->assertArrayHasKey('slice_of_lives', $this->schema->tables);
    }

    /**
     * Documents why migration file ordering matters: when Schema::table()
     * runs before Schema::create() for the same table, the create replaces
     * the auto-created table, losing columns added by Schema::table().
     *
     * This is the actual bug from issue #513 — on filesystems where
     * RecursiveIteratorIterator doesn't return alphabetical order (ext4/Linux),
     * migration files could be processed in wrong order.
     */
    #[Test]
    public function wrong_order_schema_table_before_create_loses_alter_columns(): void
    {
        $schema = new SchemaAggregator();

        // Process the alter migration FIRST (wrong order)
        $this->addMigrationCode($schema, <<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::table('users', function (Blueprint $table) {
                        $table->string('contact_sort_order')->default('last_updated');
                    });
                }
            };
            PHP);

        // Then process the create migration (should have been first)
        $this->addMigrationCode($schema, <<<'PHP'
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
                        $table->string('email');
                        $table->timestamps();
                    });
                }
            };
            PHP);

        // Schema::create() replaces the table, so the alter column is lost.
        // This documents the failure mode that sorting prevents.
        $this->assertTableHasNotNullableColumnOfType('name', 'string', $schema->tables['users']);
        $this->assertArrayNotHasKey(
            'contact_sort_order',
            $schema->tables['users']->columns,
            'Schema::create() after Schema::table() wipes alter columns — this is the bug that migration file sorting prevents',
        );
    }

    /**
     * Proves that correct order (create before alter) preserves all columns.
     * This is the complementary test — same migrations as above, correct order.
     */
    #[Test]
    public function correct_order_schema_create_before_table_preserves_all_columns(): void
    {
        $schema = new SchemaAggregator();

        // Process the create migration FIRST (correct order)
        $this->addMigrationCode($schema, <<<'PHP'
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
                        $table->string('email');
                        $table->timestamps();
                    });
                }
            };
            PHP);

        // Then process the alter migration (correct order)
        $this->addMigrationCode($schema, <<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::table('users', function (Blueprint $table) {
                        $table->string('contact_sort_order')->default('last_updated');
                    });
                }
            };
            PHP);

        // Both original and alter columns are present
        $this->assertTableHasNotNullableColumnOfType('name', 'string', $schema->tables['users']);
        $this->assertTableHasNotNullableColumnOfType('contact_sort_order', 'string', $schema->tables['users']);
        $this->assertColumnHasDefault('last_updated', $schema->tables['users']->columns['contact_sort_order']);
    }

    private function addMigrationCode(SchemaAggregator $schema, string $code): void
    {
        $hasErrors = false;
        $statements = StatementsProvider::parseStatements($code, \PHP_VERSION_ID, $hasErrors);
        $schema->addStatements($statements);
    }
}
