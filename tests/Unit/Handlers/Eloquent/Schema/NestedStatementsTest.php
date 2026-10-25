<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;

/**
 * Tests that Schema calls and Blueprint method calls inside nested block
 * structures (if/else, try/catch, foreach, etc.) are discovered correctly.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/505
 */
#[CoversClass(SchemaAggregator::class)]
final class NestedStatementsTest extends AbstractSchemaAggregatorTestCase
{
    #[Test]
    public function schema_table_on_unknown_table_auto_creates_it(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    // No Schema::create('users') — the table was created elsewhere
                    Schema::table('users', static function (Blueprint $table) {
                        $table->text('two_factor_secret')->nullable();
                    });
                }
            };
            PHP);

        $this->assertTableHasNullableColumnOfType('two_factor_secret', 'string', $schema->tables['users']);
    }

    #[Test]
    public function schema_calls_are_discovered_inside_conditional_blocks(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    if (!Schema::hasColumn('users', 'contact_sort_order')) {
                        Schema::table('users', static function (Blueprint $table) {
                            $table->string('contact_sort_order')->default('asc');
                        });
                    }

                    if (Schema::hasTable('posts')) {
                        // skip
                    } else {
                        Schema::create('posts', static function (Blueprint $table) {
                            $table->id();
                            $table->boolean('in_else')->default(true);
                        });
                    }

                    if (false) {
                        // skip
                    } elseif (true) {
                        Schema::table('logins', static function (Blueprint $table) {
                            $table->integer('in_elseif')->unsigned()->default(0);
                        });
                    }

                    switch (config('database.default')) {
                        case 'mysql':
                            Schema::table('settings', static function (Blueprint $table) {
                                $table->json('in_switch')->nullable();
                            });
                            break;
                    }

                    if (true) {
                        try {
                            Schema::table('avatars', static function (Blueprint $table) {
                                $table->string('deeply_nested')->nullable();
                            });
                        } catch (\Throwable $e) {
                            // ignore
                        }
                    }
                }
            };
            PHP);

        $this->assertTableHasNotNullableColumnOfType('contact_sort_order', 'string', $schema->tables['users']);
        $this->assertColumnHasDefault('asc', $schema->tables['users']->columns['contact_sort_order']);
        $this->assertTableHasNotNullableColumnOfType('in_else', 'bool', $schema->tables['posts']);
        $this->assertTableHasNotNullableColumnOfType('in_elseif', 'int', $schema->tables['logins']);
        $this->assertTableHasColumn('in_switch', $schema->tables['settings']);
        $this->assertTableHasNullableColumnOfType('deeply_nested', 'string', $schema->tables['avatars']);
    }

    #[Test]
    public function schema_calls_are_discovered_inside_loops(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    foreach (['posts', 'comments'] as $tableName) {
                        Schema::create('in_foreach', static function (Blueprint $table) {
                            $table->string('action');
                        });
                    }

                    for ($i = 0; $i < 1; $i++) {
                        Schema::create('in_for', static function (Blueprint $table) {
                            $table->float('value');
                        });
                    }

                    while (false) {
                        Schema::create('in_while', static function (Blueprint $table) {
                            $table->string('name');
                        });
                    }

                    do {
                        Schema::create('in_do_while', static function (Blueprint $table) {
                            $table->text('message');
                        });
                    } while (false);
                }
            };
            PHP);

        $this->assertTableHasNotNullableColumnOfType('action', 'string', $schema->tables['in_foreach']);
        $this->assertTableHasNotNullableColumnOfType('value', 'float', $schema->tables['in_for']);
        $this->assertTableHasNotNullableColumnOfType('name', 'string', $schema->tables['in_while']);
        $this->assertTableHasNotNullableColumnOfType('message', 'string', $schema->tables['in_do_while']);
    }

    #[Test]
    public function schema_calls_are_discovered_in_try_catch_and_finally_blocks(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    try {
                        Schema::table('in_try', static function (Blueprint $table) {
                            $table->string('avatar_url')->nullable();
                        });
                    } catch (\Exception $e) {
                        Schema::table('in_catch', static function (Blueprint $table) {
                            $table->string('fallback_field')->nullable();
                        });
                    } finally {
                        Schema::table('in_finally', static function (Blueprint $table) {
                            $table->boolean('migrated')->default(true);
                        });
                    }
                }
            };
            PHP);

        $this->assertTableHasNullableColumnOfType('avatar_url', 'string', $schema->tables['in_try']);
        $this->assertTableHasNullableColumnOfType('fallback_field', 'string', $schema->tables['in_catch']);
        $this->assertTableHasNotNullableColumnOfType('migrated', 'bool', $schema->tables['in_finally']);
        $this->assertColumnHasDefault(true, $schema->tables['in_finally']->columns['migrated']);
    }

    #[Test]
    public function blueprint_calls_are_discovered_inside_nested_blocks(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('users', static function (Blueprint $table) {
                        $table->id();
                        $table->string('email');

                        if (config('app.feature_flag')) {
                            $table->string('nickname')->nullable();
                        }

                        try {
                            $table->decimal('total_amount');
                        } catch (\Exception $e) {
                            // column already exists
                        }
                    });
                }
            };
            PHP);

        $table = $schema->tables['users'];
        $this->assertTableHasNotNullableColumnOfType('email', 'string', $table);
        $this->assertTableHasNullableColumnOfType('nickname', 'string', $table);
        $this->assertTableHasNotNullableColumnOfType('total_amount', 'float', $table);
    }

    #[Test]
    public function schema_create_resets_table_even_if_it_already_exists(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('jobs', static function (Blueprint $table) {
                        $table->id();
                        $table->string('old_column');
                    });

                    Schema::dropIfExists('jobs');

                    Schema::create('jobs', static function (Blueprint $table) {
                        $table->id();
                        $table->string('new_column');
                    });
                }
            };
            PHP);

        $table = $schema->tables['jobs'];
        $this->assertTableHasNotNullableColumnOfType('new_column', 'string', $table);
        $this->assertArrayNotHasKey('old_column', $table->columns);
    }

    #[Test]
    public function schema_drop_inside_nested_block(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('temp', static function (Blueprint $table) {
                        $table->id();
                    });

                    if (true) {
                        Schema::dropIfExists('temp');
                    }
                }
            };
            PHP);

        $this->assertArrayNotHasKey('temp', $schema->tables);
    }

    #[Test]
    public function flattening_does_not_recurse_into_closures(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('products', static function (Blueprint $table) {
                        $table->id();
                        $table->string('name');
                        $table->after('name', static function (Blueprint $inner) {
                            if (true) {
                                $inner->string('sku');
                            }
                        });
                    });
                }
            };
            PHP);

        $table = $schema->tables['products'];
        $this->assertTableHasNotNullableColumnOfType('name', 'string', $table);
        // sku is inside an if block within the ->after() closure — the after()
        // handler recursively calls processColumnUpdates with the closure's own
        // parameter name, which then flattens the closure's statements. This
        // verifies the column is found exactly once with the correct binding.
        $this->assertTableHasNotNullableColumnOfType('sku', 'string', $table);
    }

    #[Test]
    public function schema_table_after_schema_create_does_not_reset(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('users', static function (Blueprint $table) {
                        $table->id();
                        $table->string('email');
                    });

                    Schema::table('users', static function (Blueprint $table) {
                        $table->string('name');
                    });
                }
            };
            PHP);

        $table = $schema->tables['users'];
        // Schema::table() must NOT reset the table — both columns should exist
        $this->assertTableHasNotNullableColumnOfType('email', 'string', $table);
        $this->assertTableHasNotNullableColumnOfType('name', 'string', $table);
    }
}
