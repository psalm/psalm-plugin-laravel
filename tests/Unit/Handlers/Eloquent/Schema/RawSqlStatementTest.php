<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;

#[CoversClass(SchemaAggregator::class)]
final class RawSqlStatementTest extends AbstractSchemaAggregatorTestCase
{
    #[Test]
    public function db_statement_create_table_is_parsed(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Support\Facades\DB;

            return new class extends Migration {
                public function up(): void
                {
                    DB::statement('CREATE TABLE `audit_logs` (`id` bigint unsigned NOT NULL, `note` varchar(255) NULL)');
                }
            };
            PHP);

        $table = $schema->tables['audit_logs'];
        $this->assertTableHasNotNullableColumnOfType('id', 'int', $table);
        $this->assertTableHasNullableColumnOfType('note', 'string', $table);
    }

    #[Test]
    public function db_unprepared_heredoc_and_concatenation_are_parsed(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Support\Facades\DB;

            return new class extends Migration {
                public function up(): void
                {
                    DB::unprepared(<<<'SQL'
                        CREATE TABLE "events" (
                            "id" bigint NOT NULL,
                            "name" varchar(255) NOT NULL
                        );
                        SQL);

                    DB::statement('CREATE TABLE ' . '`jobs_archive`' . ' (`id` int NOT NULL)');
                }
            };
            PHP);

        $this->assertTableHasNotNullableColumnOfType('name', 'string', $schema->tables['events']);
        $this->assertTableHasColumn('id', $schema->tables['jobs_archive']);
    }

    #[Test]
    public function table_created_by_sql_can_be_altered_by_later_blueprint_calls(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\DB;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    DB::statement('CREATE TABLE `audit_logs` (`id` bigint NOT NULL, `legacy` text NULL)');

                    Schema::table('audit_logs', function (Blueprint $table) {
                        $table->string('actor');
                        $table->dropColumn('legacy');
                    });
                }
            };
            PHP);

        $table = $schema->tables['audit_logs'];
        $this->assertTableHasColumn('id', $table);
        $this->assertTableHasNotNullableColumnOfType('actor', 'string', $table);
        $this->assertArrayNotHasKey('legacy', $table->columns);
    }

    #[Test]
    public function dynamic_sql_is_skipped(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Support\Facades\DB;

            return new class extends Migration {
                public function up(): void
                {
                    $name = 'dynamic_one';
                    DB::statement("CREATE TABLE {$name} (id int NOT NULL)");
                    DB::statement('CREATE TABLE ' . $name . ' (id int NOT NULL)');
                    DB::statement(sprintf('CREATE TABLE %s (id int NOT NULL)', $name));
                }
            };
            PHP);

        $this->assertSame([], $schema->tables);
    }

    #[Test]
    public function non_create_table_sql_is_ignored(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Support\Facades\DB;

            return new class extends Migration {
                public function up(): void
                {
                    DB::statement('ALTER TABLE users ADD COLUMN age int');
                    DB::unprepared("INSERT INTO users (name) VALUES ('a')");
                }
            };
            PHP);

        $this->assertSame([], $schema->tables);
    }

    #[Test]
    public function sql_in_down_is_ignored(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Support\Facades\DB;

            return new class extends Migration {
                public function up(): void
                {
                }

                public function down(): void
                {
                    DB::statement('CREATE TABLE `restored` (`id` int NOT NULL)');
                }
            };
            PHP);

        $this->assertSame([], $schema->tables);
    }

    #[Test]
    public function fully_qualified_facade_is_recognized(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;

            return new class extends Migration {
                public function up(): void
                {
                    \Illuminate\Support\Facades\DB::statement('CREATE TABLE `fq` (`id` int NOT NULL)');
                }
            };
            PHP);

        $this->assertTableHasColumn('id', $schema->tables['fq']);
    }

    #[Test]
    public function unrelated_db_class_is_ignored(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            namespace Database\Migrations;

            use App\Support\DB;
            use Illuminate\Database\Migrations\Migration;

            return new class extends Migration {
                public function up(): void
                {
                    DB::statement('CREATE TABLE `userland` (`id` int NOT NULL)');
                }
            };
            PHP);

        $this->assertSame([], $schema->tables);
    }

    #[Test]
    public function other_db_methods_are_ignored(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Support\Facades\DB;

            return new class extends Migration {
                public function up(): void
                {
                    DB::select('CREATE TABLE `selected` (`id` int NOT NULL)');
                }
            };
            PHP);

        $this->assertSame([], $schema->tables);
    }

    #[Test]
    public function temporary_tables_are_skipped(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Support\Facades\DB;

            return new class extends Migration {
                public function up(): void
                {
                    DB::statement('CREATE TEMPORARY TABLE `scratch` (`id` int NOT NULL)');
                }
            };
            PHP);

        $this->assertSame([], $schema->tables);
    }

    #[Test]
    public function create_table_like_does_not_register_a_bogus_column(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Support\Facades\DB;

            return new class extends Migration {
                public function up(): void
                {
                    DB::statement('CREATE TABLE `copy` (LIKE `original`)');
                }
            };
            PHP);

        $this->assertSame([], $schema->tables);
    }

    #[Test]
    public function if_not_exists_keeps_an_already_known_table(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\DB;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('a', function (Blueprint $table) {
                        $table->string('kept');
                    });

                    DB::statement('CREATE TABLE IF NOT EXISTS `a` (`replaced` int NOT NULL)');
                    DB::statement('CREATE TABLE IF NOT EXISTS `b` (`fresh` int NOT NULL)');
                }
            };
            PHP);

        $this->assertTableHasColumn('kept', $schema->tables['a']);
        $this->assertArrayNotHasKey('replaced', $schema->tables['a']->columns);
        $this->assertTableHasColumn('fresh', $schema->tables['b']);
    }

    #[Test]
    public function plain_create_table_replaces_an_already_known_table(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\DB;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('a', function (Blueprint $table) {
                        $table->string('old');
                    });

                    DB::statement('CREATE TABLE `a` (`new` int NOT NULL)');
                }
            };
            PHP);

        $this->assertTableHasColumn('new', $schema->tables['a']);
        $this->assertArrayNotHasKey('old', $schema->tables['a']->columns);
    }

    #[Test]
    public function multiple_statements_in_one_string_are_all_parsed(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Support\Facades\DB;

            return new class extends Migration {
                public function up(): void
                {
                    DB::unprepared(<<<'SQL'
                        CREATE TABLE `first` (`id` int NOT NULL);
                        INSERT INTO `first` (`id`) VALUES (1);
                        CREATE TABLE `second` (`id` int NOT NULL, `label` varchar(20) NULL);
                        SQL);
                }
            };
            PHP);

        $this->assertTableHasColumn('id', $schema->tables['first']);
        $this->assertTableHasNullableColumnOfType('label', 'string', $schema->tables['second']);
    }

    #[Test]
    public function interpolated_heredoc_is_declined(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Support\Facades\DB;

            return new class extends Migration {
                public function up(): void
                {
                    $table = 'interpolated';
                    DB::unprepared(<<<SQL
                        CREATE TABLE {$table} (id int NOT NULL)
                        SQL);
                }
            };
            PHP);

        $this->assertSame([], $schema->tables);
    }

    #[Test]
    public function sql_comments_are_stripped(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Support\Facades\DB;

            return new class extends Migration {
                public function up(): void
                {
                    DB::unprepared(<<<'SQL'
                        -- CREATE TABLE `commented_line` (`id` int NOT NULL);
                        # CREATE TABLE `commented_hash` (`id` int NOT NULL);
                        /* CREATE TABLE `commented_block` (`id` int NOT NULL); */
                        CREATE TABLE `real` (
                            `id` int NOT NULL, -- primary key
                            # a MySQL comment
                            `note` varchar(20) NULL, /* inline */
                            `url` varchar(50) NOT NULL DEFAULT 'http://x/#a--b/*c*/'
                        );
                        SQL);
                }
            };
            PHP);

        $this->assertSame(['real'], \array_keys($schema->tables));
        $table = $schema->tables['real'];
        $this->assertTableHasNotNullableColumnOfType('id', 'int', $table);
        $this->assertTableHasNullableColumnOfType('note', 'string', $table);
        $this->assertTableHasNotNullableColumnOfType('url', 'string', $table);
    }
}
