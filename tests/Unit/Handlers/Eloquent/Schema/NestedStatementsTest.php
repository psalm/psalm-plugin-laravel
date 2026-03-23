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
                        $table->timestamp('two_factor_confirmed_at')->nullable();
                    });
                }
            };
            PHP);

        $this->assertArrayHasKey('users', $schema->tables);
        $this->assertTableHasNullableColumnOfType('two_factor_secret', 'string', $schema->tables['users']);
        $this->assertTableHasNullableColumnOfType('two_factor_confirmed_at', 'string', $schema->tables['users']);
    }

    #[Test]
    public function schema_call_inside_if_block(): void
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
                }
            };
            PHP);

        $this->assertArrayHasKey('users', $schema->tables);
        $this->assertTableHasNotNullableColumnOfType('contact_sort_order', 'string', $schema->tables['users']);
        $this->assertColumnHasDefault('asc', $schema->tables['users']->columns['contact_sort_order']);
    }

    #[Test]
    public function schema_call_inside_if_else_blocks(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    if (Schema::hasTable('posts')) {
                        Schema::table('posts', static function (Blueprint $table) {
                            $table->boolean('can_be_deleted')->default(true);
                        });
                    } else {
                        Schema::create('posts', static function (Blueprint $table) {
                            $table->id();
                            $table->boolean('can_be_deleted')->default(true);
                        });
                    }
                }
            };
            PHP);

        $this->assertArrayHasKey('posts', $schema->tables);
        // Both branches define can_be_deleted — the last one wins
        $this->assertTableHasColumn('can_be_deleted', $schema->tables['posts']);
    }

    #[Test]
    public function schema_call_inside_try_catch(): void
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
                        Schema::table('users', static function (Blueprint $table) {
                            $table->string('avatar_url')->nullable();
                        });
                    } catch (\Exception $e) {
                        // table might not exist
                    }
                }
            };
            PHP);

        $this->assertArrayHasKey('users', $schema->tables);
        $this->assertTableHasNullableColumnOfType('avatar_url', 'string', $schema->tables['users']);
    }

    #[Test]
    public function schema_call_inside_foreach(): void
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
                        Schema::create('audit_log', static function (Blueprint $table) {
                            $table->id();
                            $table->string('action');
                        });
                    }
                }
            };
            PHP);

        $this->assertArrayHasKey('audit_log', $schema->tables);
        $this->assertTableHasNotNullableColumnOfType('action', 'string', $schema->tables['audit_log']);
    }

    #[Test]
    public function blueprint_call_inside_if_block(): void
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
                    });
                }
            };
            PHP);

        $table = $schema->tables['users'];
        $this->assertTableHasNotNullableColumnOfType('email', 'string', $table);
        $this->assertTableHasNullableColumnOfType('nickname', 'string', $table);
    }

    #[Test]
    public function deeply_nested_schema_calls(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    if (true) {
                        try {
                            Schema::table('users', static function (Blueprint $table) {
                                $table->string('distant_uuid')->nullable();
                            });
                        } catch (\Throwable $e) {
                            // ignore
                        }
                    }
                }
            };
            PHP);

        $this->assertArrayHasKey('users', $schema->tables);
        $this->assertTableHasNullableColumnOfType('distant_uuid', 'string', $schema->tables['users']);
    }

    #[Test]
    public function multiple_schema_table_calls_on_same_unknown_table(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::table('templates', static function (Blueprint $table) {
                        $table->boolean('can_be_deleted')->default(true);
                    });

                    Schema::table('templates', static function (Blueprint $table) {
                        $table->string('label')->nullable();
                    });
                }
            };
            PHP);

        $table = $schema->tables['templates'];
        $this->assertTableHasColumn('can_be_deleted', $table);
        $this->assertTableHasNullableColumnOfType('label', 'string', $table);
    }

    #[Test]
    public function schema_call_inside_elseif_block(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    if (false) {
                        // skip
                    } elseif (true) {
                        Schema::table('users', static function (Blueprint $table) {
                            $table->integer('login_count')->unsigned()->default(0);
                        });
                    }
                }
            };
            PHP);

        $this->assertArrayHasKey('users', $schema->tables);
        $this->assertTableHasNotNullableColumnOfType('login_count', 'int', $schema->tables['users']);
    }

    #[Test]
    public function schema_call_inside_switch_case(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    switch (config('database.default')) {
                        case 'mysql':
                            Schema::table('users', static function (Blueprint $table) {
                                $table->json('metadata')->nullable();
                            });
                            break;
                        default:
                            Schema::table('users', static function (Blueprint $table) {
                                $table->text('metadata')->nullable();
                            });
                    }
                }
            };
            PHP);

        $this->assertArrayHasKey('users', $schema->tables);
        $this->assertTableHasColumn('metadata', $schema->tables['users']);
    }

    #[Test]
    public function blueprint_call_inside_try_catch(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('orders', static function (Blueprint $table) {
                        $table->id();

                        try {
                            $table->decimal('total_amount');
                        } catch (\Exception $e) {
                            // column already exists
                        }
                    });
                }
            };
            PHP);

        $table = $schema->tables['orders'];
        $this->assertTableHasNotNullableColumnOfType('total_amount', 'float', $table);
    }

    #[Test]
    public function fortify_style_migration_pattern(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::table('users', static function (Blueprint $table) {
                        $table->text('two_factor_secret')
                            ->after('password')
                            ->nullable();

                        $table->text('two_factor_recovery_codes')
                            ->after('two_factor_secret')
                            ->nullable();

                        $table->timestamp('two_factor_confirmed_at')
                            ->after('two_factor_recovery_codes')
                            ->nullable();
                    });
                }
            };
            PHP);

        $table = $schema->tables['users'];
        $this->assertTableHasNullableColumnOfType('two_factor_secret', 'string', $table);
        $this->assertTableHasNullableColumnOfType('two_factor_recovery_codes', 'string', $table);
        $this->assertTableHasNullableColumnOfType('two_factor_confirmed_at', 'string', $table);
    }

    #[Test]
    public function schema_call_inside_for_loop(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    for ($i = 0; $i < 1; $i++) {
                        Schema::create('metrics', static function (Blueprint $table) {
                            $table->id();
                            $table->float('value');
                        });
                    }
                }
            };
            PHP);

        $this->assertTableHasNotNullableColumnOfType('value', 'float', $schema->tables['metrics']);
    }

    #[Test]
    public function schema_call_inside_while_loop(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    while (false) {
                        Schema::create('events', static function (Blueprint $table) {
                            $table->id();
                            $table->string('name');
                        });
                    }
                }
            };
            PHP);

        $this->assertArrayHasKey('events', $schema->tables);
        $this->assertTableHasNotNullableColumnOfType('name', 'string', $schema->tables['events']);
    }

    #[Test]
    public function schema_call_inside_do_while(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    do {
                        Schema::create('logs', static function (Blueprint $table) {
                            $table->id();
                            $table->text('message');
                        });
                    } while (false);
                }
            };
            PHP);

        $this->assertTableHasNotNullableColumnOfType('message', 'string', $schema->tables['logs']);
    }

    #[Test]
    public function schema_call_inside_catch_block(): void
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
                        // might fail
                    } catch (\Exception $e) {
                        Schema::table('users', static function (Blueprint $table) {
                            $table->string('fallback_field')->nullable();
                        });
                    }
                }
            };
            PHP);

        $this->assertTableHasNullableColumnOfType('fallback_field', 'string', $schema->tables['users']);
    }

    #[Test]
    public function schema_call_inside_finally_block(): void
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
                        // might fail
                    } finally {
                        Schema::table('users', static function (Blueprint $table) {
                            $table->boolean('migrated')->default(true);
                        });
                    }
                }
            };
            PHP);

        $table = $schema->tables['users'];
        $this->assertTableHasNotNullableColumnOfType('migrated', 'bool', $table);
        $this->assertColumnHasDefault(true, $table->columns['migrated']);
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
}
