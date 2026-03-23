<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;

/**
 * Tests that Schema calls in helper methods (not just up()) are processed.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/507
 */
#[CoversClass(SchemaAggregator::class)]
final class HelperMethodTest extends AbstractSchemaAggregatorTestCase
{
    #[Test]
    public function schema_calls_in_helper_method_are_detected(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    $this->createUsersTable();
                }

                private function createUsersTable(): void
                {
                    Schema::create('users', function (Blueprint $table) {
                        $table->id();
                        $table->string('email');
                    });
                }
            };
            PHP);

        $this->assertArrayHasKey('users', $schema->tables);
        $this->assertTableHasNotNullableColumnOfType('id', 'int', $schema->tables['users']);
        $this->assertTableHasNotNullableColumnOfType('email', 'string', $schema->tables['users']);
    }

    #[Test]
    public function schema_calls_in_multiple_helper_methods_are_detected(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    $this->createUsersTable();
                    $this->createPostsTable();
                }

                private function createUsersTable(): void
                {
                    Schema::create('users', function (Blueprint $table) {
                        $table->id();
                        $table->string('name');
                    });
                }

                private function createPostsTable(): void
                {
                    Schema::create('posts', function (Blueprint $table) {
                        $table->id();
                        $table->string('title');
                    });
                }
            };
            PHP);

        $this->assertArrayHasKey('users', $schema->tables);
        $this->assertTableHasNotNullableColumnOfType('name', 'string', $schema->tables['users']);

        $this->assertArrayHasKey('posts', $schema->tables);
        $this->assertTableHasNotNullableColumnOfType('title', 'string', $schema->tables['posts']);
    }

    #[Test]
    public function down_method_is_excluded(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    // intentionally empty
                }

                public function down(): void
                {
                    Schema::create('ghosts', function (Blueprint $table) {
                        $table->id();
                    });
                }
            };
            PHP);

        $this->assertArrayNotHasKey('ghosts', $schema->tables);
    }

    #[Test]
    public function schema_calls_in_non_standard_methods_are_detected(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    $this->setup();
                }

                /** A public method that is neither up() nor down() */
                public function setup(): void
                {
                    Schema::create('configs', function (Blueprint $table) {
                        $table->id();
                        $table->string('key');
                    });
                }
            };
            PHP);

        $this->assertArrayHasKey('configs', $schema->tables);
        $this->assertTableHasNotNullableColumnOfType('key', 'string', $schema->tables['configs']);
    }
}
