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
}
