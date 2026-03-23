<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumnDefault;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SqlSchemaParser;

#[CoversClass(SqlSchemaParser::class)]
final class SqlSchemaParserTest extends TestCase
{
    private SqlSchemaParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new SqlSchemaParser();
    }

    private function parse(string $sql): SchemaAggregator
    {
        $aggregator = new SchemaAggregator();
        $this->parser->addToAggregator($sql, $aggregator);

        return $aggregator;
    }

    // ──────────────────────────────────────────────────
    // MySQL: basic CREATE TABLE parsing
    // ──────────────────────────────────────────────────

    #[Test]
    public function it_parses_mysql_create_table(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE `users` (
              `id` bigint unsigned NOT NULL AUTO_INCREMENT,
              `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
              `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `users_email_unique` (`email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL;

        $schema = $this->parse($sql);

        $this->assertArrayHasKey('users', $schema->tables);

        $table = $schema->tables['users'];
        $this->assertCount(3, $table->columns);

        $this->assertSame(SchemaColumn::TYPE_INT, $table->columns['id']->type);
        $this->assertTrue($table->columns['id']->unsigned);
        $this->assertFalse($table->columns['id']->nullable);

        $this->assertSame(SchemaColumn::TYPE_STRING, $table->columns['name']->type);
        $this->assertFalse($table->columns['name']->nullable);

        $this->assertSame(SchemaColumn::TYPE_STRING, $table->columns['email']->type);
    }

    #[Test]
    public function it_parses_multiple_tables(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE `users` (
              `id` bigint unsigned NOT NULL AUTO_INCREMENT,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB;
            CREATE TABLE `posts` (
              `id` bigint unsigned NOT NULL AUTO_INCREMENT,
              `user_id` bigint unsigned NOT NULL,
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB;
            SQL;

        $schema = $this->parse($sql);

        $this->assertCount(2, $schema->tables);
        $this->assertArrayHasKey('users', $schema->tables);
        $this->assertArrayHasKey('posts', $schema->tables);
    }

    // ──────────────────────────────────────────────────
    // MySQL: integer types
    // ──────────────────────────────────────────────────

    #[Test]
    public function it_parses_mysql_integer_types(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE `test` (
              `col_int` int NOT NULL,
              `col_bigint` bigint unsigned NOT NULL,
              `col_mediumint` mediumint unsigned NOT NULL,
              `col_smallint` smallint NOT NULL,
              `col_tinyint` tinyint unsigned NOT NULL
            );
            SQL;

        $table = $this->parse($sql)->tables['test'];

        $this->assertSame(SchemaColumn::TYPE_INT, $table->columns['col_int']->type);
        $this->assertFalse($table->columns['col_int']->unsigned);

        $this->assertSame(SchemaColumn::TYPE_INT, $table->columns['col_bigint']->type);
        $this->assertTrue($table->columns['col_bigint']->unsigned);

        $this->assertSame(SchemaColumn::TYPE_INT, $table->columns['col_mediumint']->type);
        $this->assertTrue($table->columns['col_mediumint']->unsigned);

        $this->assertSame(SchemaColumn::TYPE_INT, $table->columns['col_smallint']->type);

        $this->assertSame(SchemaColumn::TYPE_INT, $table->columns['col_tinyint']->type);
        $this->assertTrue($table->columns['col_tinyint']->unsigned);
    }

    #[Test]
    public function it_parses_int_with_display_width(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE `test` (
              `col` int(11) NOT NULL
            );
            SQL;

        $table = $this->parse($sql)->tables['test'];
        $this->assertSame(SchemaColumn::TYPE_INT, $table->columns['col']->type);
    }

    // ──────────────────────────────────────────────────
    // MySQL: tinyint(1) → boolean
    // ──────────────────────────────────────────────────

    #[Test]
    public function it_maps_tinyint_1_to_boolean(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE `test` (
              `is_active` tinyint(1) NOT NULL,
              `is_visible` tinyint(1) NOT NULL DEFAULT '0'
            );
            SQL;

        $table = $this->parse($sql)->tables['test'];
        $this->assertSame(SchemaColumn::TYPE_BOOL, $table->columns['is_active']->type);
        $this->assertSame(SchemaColumn::TYPE_BOOL, $table->columns['is_visible']->type);
    }

    #[Test]
    public function it_does_not_map_tinyint_without_1_to_boolean(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE `test` (
              `priority` tinyint unsigned NOT NULL,
              `level` tinyint(4) NOT NULL
            );
            SQL;

        $table = $this->parse($sql)->tables['test'];
        $this->assertSame(SchemaColumn::TYPE_INT, $table->columns['priority']->type);
        $this->assertSame(SchemaColumn::TYPE_INT, $table->columns['level']->type);
    }

    // ──────────────────────────────────────────────────
    // MySQL: string types
    // ──────────────────────────────────────────────────

    #[Test]
    public function it_parses_mysql_string_types(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE `test` (
              `col_varchar` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
              `col_char` char(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
              `col_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
              `col_mediumtext` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
              `col_longtext` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
              `col_json` json NOT NULL,
              `col_date` date NOT NULL,
              `col_datetime` datetime NOT NULL,
              `col_timestamp` timestamp NULL DEFAULT NULL
            );
            SQL;

        $table = $this->parse($sql)->tables['test'];

        foreach ($table->columns as $column) {
            $this->assertSame(SchemaColumn::TYPE_STRING, $column->type, "Column {$column->name} should be string");
        }
    }

    // ──────────────────────────────────────────────────
    // MySQL: float types
    // ──────────────────────────────────────────────────

    #[Test]
    public function it_parses_mysql_float_types(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE `test` (
              `col_decimal` decimal(5,2) NOT NULL,
              `col_float` float NOT NULL,
              `col_double` double NOT NULL
            );
            SQL;

        $table = $this->parse($sql)->tables['test'];
        $this->assertSame(SchemaColumn::TYPE_FLOAT, $table->columns['col_decimal']->type);
        $this->assertSame(SchemaColumn::TYPE_FLOAT, $table->columns['col_float']->type);
        $this->assertSame(SchemaColumn::TYPE_FLOAT, $table->columns['col_double']->type);
    }

    // ──────────────────────────────────────────────────
    // MySQL: nullability
    // ──────────────────────────────────────────────────

    #[Test]
    public function it_parses_nullability(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE `test` (
              `required` varchar(255) NOT NULL,
              `optional_null` varchar(255) DEFAULT NULL,
              `optional_explicit` varchar(255) NULL DEFAULT NULL,
              `optional_implicit` varchar(255)
            );
            SQL;

        $table = $this->parse($sql)->tables['test'];

        $this->assertFalse($table->columns['required']->nullable);
        $this->assertTrue($table->columns['optional_null']->nullable);
        $this->assertTrue($table->columns['optional_explicit']->nullable);
        $this->assertTrue($table->columns['optional_implicit']->nullable);
    }

    // ──────────────────────────────────────────────────
    // MySQL: default values
    // ──────────────────────────────────────────────────

    #[Test]
    public function it_parses_default_null(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE `test` (
              `col` varchar(255) DEFAULT NULL
            );
            SQL;

        $column = $this->parse($sql)->tables['test']->columns['col'];
        $this->assertInstanceOf(SchemaColumnDefault::class, $column->default);
        $this->assertTrue($column->default->resolvable);
        $this->assertNull($column->default->value);
    }

    #[Test]
    public function it_parses_default_string(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE `test` (
              `status` varchar(20) NOT NULL DEFAULT 'draft'
            );
            SQL;

        $column = $this->parse($sql)->tables['test']->columns['status'];
        $this->assertInstanceOf(SchemaColumnDefault::class, $column->default);
        $this->assertTrue($column->default->resolvable);
        $this->assertSame('draft', $column->default->value);
    }

    #[Test]
    public function it_parses_default_integer(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE `test` (
              `quantity` int NOT NULL DEFAULT 0
            );
            SQL;

        $column = $this->parse($sql)->tables['test']->columns['quantity'];
        $this->assertInstanceOf(SchemaColumnDefault::class, $column->default);
        $this->assertSame(0, $column->default->value);
    }

    #[Test]
    public function it_parses_no_default_as_null(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE `test` (
              `name` varchar(255) NOT NULL
            );
            SQL;

        $column = $this->parse($sql)->tables['test']->columns['name'];
        $this->assertNotInstanceOf(SchemaColumnDefault::class, $column->default);
    }

    #[Test]
    public function it_marks_function_default_as_unresolvable(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE `test` (
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
            SQL;

        $column = $this->parse($sql)->tables['test']->columns['created_at'];
        $this->assertInstanceOf(SchemaColumnDefault::class, $column->default);
        $this->assertFalse($column->default->resolvable);
    }

    // ──────────────────────────────────────────────────
    // MySQL: enum
    // ──────────────────────────────────────────────────

    #[Test]
    public function it_parses_mysql_enum(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE `test` (
              `status` enum('draft','published','archived') NOT NULL DEFAULT 'draft'
            );
            SQL;

        $column = $this->parse($sql)->tables['test']->columns['status'];
        $this->assertSame(SchemaColumn::TYPE_ENUM, $column->type);
        $this->assertSame(['draft', 'published', 'archived'], $column->options);
        $this->assertInstanceOf(SchemaColumnDefault::class, $column->default);
        $this->assertSame('draft', $column->default->value);
    }

    // ──────────────────────────────────────────────────
    // MySQL: realistic schema dump
    // ──────────────────────────────────────────────────

    #[Test]
    public function it_parses_realistic_mysql_dump(): void
    {
        $sql = <<<'SQL'
            /*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
            /*!40103 SET TIME_ZONE='+00:00' */;
            DROP TABLE IF EXISTS `users`;
            /*!40101 SET @saved_cs_client     = @@character_set_client */;
            /*!40101 SET character_set_client = utf8 */;
            CREATE TABLE `users` (
              `id` bigint unsigned NOT NULL AUTO_INCREMENT,
              `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
              `email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
              `email_verified_at` timestamp NULL DEFAULT NULL,
              `password` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
              `is_admin` tinyint(1) NOT NULL DEFAULT '0',
              `balance` decimal(10,2) NOT NULL DEFAULT '0.00',
              `remember_token` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
              `created_at` timestamp NULL DEFAULT NULL,
              `updated_at` timestamp NULL DEFAULT NULL,
              PRIMARY KEY (`id`),
              UNIQUE KEY `users_email_unique` (`email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            /*!40101 SET character_set_client = @saved_cs_client */;
            SQL;

        $schema = $this->parse($sql);
        $table = $schema->tables['users'];

        $this->assertCount(10, $table->columns);

        // id: bigint unsigned NOT NULL
        $this->assertSame(SchemaColumn::TYPE_INT, $table->columns['id']->type);
        $this->assertTrue($table->columns['id']->unsigned);
        $this->assertFalse($table->columns['id']->nullable);

        // name: varchar NOT NULL
        $this->assertSame(SchemaColumn::TYPE_STRING, $table->columns['name']->type);
        $this->assertFalse($table->columns['name']->nullable);

        // email_verified_at: timestamp NULL
        $this->assertSame(SchemaColumn::TYPE_STRING, $table->columns['email_verified_at']->type);
        $this->assertTrue($table->columns['email_verified_at']->nullable);

        // is_admin: tinyint(1) → bool
        $this->assertSame(SchemaColumn::TYPE_BOOL, $table->columns['is_admin']->type);

        // balance: decimal
        $this->assertSame(SchemaColumn::TYPE_FLOAT, $table->columns['balance']->type);

        // remember_token: varchar DEFAULT NULL
        $this->assertTrue($table->columns['remember_token']->nullable);

        // timestamps: nullable
        $this->assertTrue($table->columns['created_at']->nullable);
        $this->assertTrue($table->columns['updated_at']->nullable);
    }

    // ──────────────────────────────────────────────────
    // PostgreSQL: basic parsing
    // ──────────────────────────────────────────────────

    #[Test]
    public function it_parses_postgresql_create_table(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE public.users (
                id bigint NOT NULL,
                name character varying(255) NOT NULL,
                email character varying(255) NOT NULL,
                is_active boolean DEFAULT true NOT NULL,
                created_at timestamp(0) without time zone,
                updated_at timestamp(0) without time zone
            );
            SQL;

        $schema = $this->parse($sql);
        $table = $schema->tables['users'];

        $this->assertSame(SchemaColumn::TYPE_INT, $table->columns['id']->type);
        $this->assertSame(SchemaColumn::TYPE_STRING, $table->columns['name']->type);
        $this->assertSame(SchemaColumn::TYPE_STRING, $table->columns['email']->type);
        $this->assertSame(SchemaColumn::TYPE_BOOL, $table->columns['is_active']->type);
        $this->assertSame(SchemaColumn::TYPE_STRING, $table->columns['created_at']->type);
        $this->assertSame(SchemaColumn::TYPE_STRING, $table->columns['updated_at']->type);
    }

    #[Test]
    public function it_parses_postgresql_serial_types(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE public.test (
                id serial NOT NULL,
                big_id bigserial NOT NULL
            );
            SQL;

        $table = $this->parse($sql)->tables['test'];
        $this->assertSame(SchemaColumn::TYPE_INT, $table->columns['id']->type);
        $this->assertSame(SchemaColumn::TYPE_INT, $table->columns['big_id']->type);
    }

    #[Test]
    public function it_parses_postgresql_boolean_default(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE public.test (
                active boolean DEFAULT false NOT NULL,
                visible boolean DEFAULT true NOT NULL
            );
            SQL;

        $table = $this->parse($sql)->tables['test'];
        $this->assertSame(SchemaColumn::TYPE_BOOL, $table->columns['active']->type);
        $this->assertInstanceOf(SchemaColumnDefault::class, $table->columns['active']->default);
        $this->assertFalse($table->columns['active']->default->value);

        $this->assertInstanceOf(SchemaColumnDefault::class, $table->columns['visible']->default);
        $this->assertTrue($table->columns['visible']->default->value);
    }

    #[Test]
    public function it_parses_postgresql_double_quoted_identifiers(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE public."user-profiles" (
                "user-id" integer NOT NULL,
                "full-name" character varying(255)
            );
            SQL;

        $schema = $this->parse($sql);
        $table = $schema->tables['user-profiles'];
        $this->assertArrayHasKey('user-id', $table->columns);
        $this->assertArrayHasKey('full-name', $table->columns);
    }

    #[Test]
    public function it_parses_postgresql_jsonb(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE public.test (
                data jsonb NOT NULL,
                meta json DEFAULT '{}'::json
            );
            SQL;

        $table = $this->parse($sql)->tables['test'];
        $this->assertSame(SchemaColumn::TYPE_STRING, $table->columns['data']->type);
        $this->assertSame(SchemaColumn::TYPE_STRING, $table->columns['meta']->type);
    }

    #[Test]
    public function it_parses_postgresql_uuid_type(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE public.test (
                id uuid NOT NULL
            );
            SQL;

        $table = $this->parse($sql)->tables['test'];
        $this->assertSame(SchemaColumn::TYPE_STRING, $table->columns['id']->type);
    }

    #[Test]
    public function it_parses_postgresql_numeric_type(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE public.test (
                amount numeric(10,2) NOT NULL,
                ratio double precision NOT NULL,
                score real NOT NULL
            );
            SQL;

        $table = $this->parse($sql)->tables['test'];
        $this->assertSame(SchemaColumn::TYPE_FLOAT, $table->columns['amount']->type);
        $this->assertSame(SchemaColumn::TYPE_FLOAT, $table->columns['ratio']->type);
        $this->assertSame(SchemaColumn::TYPE_FLOAT, $table->columns['score']->type);
    }

    // ──────────────────────────────────────────────────
    // SQLite: sqlite3 .schema output
    // ──────────────────────────────────────────────────

    #[Test]
    public function it_parses_sqlite_schema_output(): void
    {
        // SQLite's `.schema --indent` produces unquoted identifiers and
        // uses INTEGER PRIMARY KEY for autoincrement, varchar without length, etc.
        $sql = <<<'SQL'
            CREATE TABLE users(
              id integer NOT NULL PRIMARY KEY AUTOINCREMENT,
              name varchar NOT NULL,
              email varchar NOT NULL,
              is_admin integer NOT NULL DEFAULT 0,
              bio text,
              created_at datetime,
              updated_at datetime
            );
            SQL;

        $table = $this->parse($sql)->tables['users'];

        $this->assertCount(7, $table->columns);
        $this->assertSame(SchemaColumn::TYPE_INT, $table->columns['id']->type);
        $this->assertSame(SchemaColumn::TYPE_STRING, $table->columns['name']->type);
        $this->assertFalse($table->columns['name']->nullable);
        $this->assertSame(SchemaColumn::TYPE_STRING, $table->columns['email']->type);
        $this->assertSame(SchemaColumn::TYPE_INT, $table->columns['is_admin']->type);
        $this->assertSame(SchemaColumn::TYPE_STRING, $table->columns['bio']->type);
        $this->assertTrue($table->columns['bio']->nullable);
        $this->assertSame(SchemaColumn::TYPE_STRING, $table->columns['created_at']->type);
    }

    #[Test]
    public function it_parses_sqlite_with_double_quoted_identifiers(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE "posts"(
              "id" integer NOT NULL PRIMARY KEY AUTOINCREMENT,
              "title" varchar NOT NULL,
              "body" text NOT NULL
            );
            SQL;

        $table = $this->parse($sql)->tables['posts'];
        $this->assertCount(3, $table->columns);
        $this->assertSame(SchemaColumn::TYPE_STRING, $table->columns['title']->type);
    }

    // ──────────────────────────────────────────────────
    // Integration: SQL dumps + PHP migrations
    // ──────────────────────────────────────────────────

    #[Test]
    public function sql_tables_are_available_in_aggregator(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE `users` (
              `id` bigint unsigned NOT NULL,
              `name` varchar(255) NOT NULL
            );
            SQL;

        $aggregator = new SchemaAggregator();
        $this->parser->addToAggregator($sql, $aggregator);

        // Verify tables are directly accessible on the aggregator,
        // same as tables from PHP migrations
        $this->assertArrayHasKey('users', $aggregator->tables);
        $this->assertArrayHasKey('id', $aggregator->tables['users']->columns);
        $this->assertArrayHasKey('name', $aggregator->tables['users']->columns);
    }

    #[Test]
    public function it_handles_empty_sql(): void
    {
        $schema = $this->parse('');
        $this->assertCount(0, $schema->tables);
    }

    #[Test]
    public function it_handles_sql_with_no_create_table(): void
    {
        $sql = <<<'SQL'
            /*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
            DROP TABLE IF EXISTS `users`;
            INSERT INTO `migrations` VALUES (1,'create_users_table',1);
            SQL;

        $schema = $this->parse($sql);
        $this->assertCount(0, $schema->tables);
    }

    #[Test]
    public function it_skips_constraint_lines(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE `posts` (
              `id` bigint unsigned NOT NULL AUTO_INCREMENT,
              `user_id` bigint unsigned NOT NULL,
              `title` varchar(255) NOT NULL,
              PRIMARY KEY (`id`),
              KEY `posts_user_id_foreign` (`user_id`),
              CONSTRAINT `posts_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
            ) ENGINE=InnoDB;
            SQL;

        $table = $this->parse($sql)->tables['posts'];

        // Only actual columns, not constraints/indexes
        $this->assertCount(3, $table->columns);
        $this->assertArrayHasKey('id', $table->columns);
        $this->assertArrayHasKey('user_id', $table->columns);
        $this->assertArrayHasKey('title', $table->columns);
    }

    // ──────────────────────────────────────────────────
    // Edge cases from real-world dumps
    // ──────────────────────────────────────────────────

    #[Test]
    public function it_handles_column_with_comment(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE `test` (
              `slug` varchar(255) NOT NULL COMMENT 'URL-friendly unique title'
            );
            SQL;

        $column = $this->parse($sql)->tables['test']->columns['slug'];
        $this->assertSame(SchemaColumn::TYPE_STRING, $column->type);
        $this->assertFalse($column->nullable);
    }

    #[Test]
    public function it_handles_mysql_set_type(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE `test` (
              `permissions` set('read','write','execute') NOT NULL
            );
            SQL;

        $column = $this->parse($sql)->tables['test']->columns['permissions'];
        $this->assertSame('set', $column->type);
        $this->assertSame(['read', 'write', 'execute'], $column->options);
    }

    #[Test]
    public function it_unescapes_sql_doubled_quotes_in_enum_options(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE `test` (
              `status` enum('it''s','won''t','O''Reilly') NOT NULL
            );
            SQL;

        $column = $this->parse($sql)->tables['test']->columns['status'];
        $this->assertSame(SchemaColumn::TYPE_ENUM, $column->type);
        $this->assertSame(["it's", "won't", "O'Reilly"], $column->options);
    }

    #[Test]
    public function it_unescapes_sql_string_defaults(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE `test` (
              `name` varchar(255) NOT NULL DEFAULT 'it''s a test'
            );
            SQL;

        $column = $this->parse($sql)->tables['test']->columns['name'];
        $this->assertInstanceOf(SchemaColumnDefault::class, $column->default);
        $this->assertSame("it's a test", $column->default->value);
    }

    #[Test]
    public function it_handles_backslash_escapes_in_comments(): void
    {
        // mysqldump may use backslash escapes in COMMENT strings
        $sql = <<<'SQL'
            CREATE TABLE `test` (
              `name` varchar(255) NOT NULL COMMENT 'it\'s a column',
              `value` int NOT NULL
            );
            SQL;

        $table = $this->parse($sql)->tables['test'];
        $this->assertCount(2, $table->columns);
        $this->assertSame(SchemaColumn::TYPE_STRING, $table->columns['name']->type);
        $this->assertSame(SchemaColumn::TYPE_INT, $table->columns['value']->type);
    }

    #[Test]
    public function it_parses_create_table_if_not_exists(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE IF NOT EXISTS `users` (
              `id` bigint unsigned NOT NULL,
              `name` varchar(255) NOT NULL
            );
            SQL;

        $table = $this->parse($sql)->tables['users'];
        $this->assertCount(2, $table->columns);
        $this->assertSame(SchemaColumn::TYPE_INT, $table->columns['id']->type);
    }

    #[Test]
    public function it_falls_back_to_mixed_for_unknown_types(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE `test` (
              `data` xml NOT NULL,
              `amount` money NOT NULL,
              `flags` bit(8) NOT NULL
            );
            SQL;

        $table = $this->parse($sql)->tables['test'];
        $this->assertSame(SchemaColumn::TYPE_MIXED, $table->columns['data']->type);
        $this->assertSame(SchemaColumn::TYPE_MIXED, $table->columns['amount']->type);
        $this->assertSame(SchemaColumn::TYPE_MIXED, $table->columns['flags']->type);
    }

    #[Test]
    public function it_handles_empty_table(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE `empty_table` (
              PRIMARY KEY (`id`)
            );
            SQL;

        $table = $this->parse($sql)->tables['empty_table'];
        $this->assertCount(0, $table->columns);
    }

    #[Test]
    public function it_parses_mysql_quoted_numeric_defaults_as_strings(): void
    {
        // mysqldump quotes numeric defaults like DEFAULT '0' and DEFAULT '0.00'
        $sql = <<<'SQL'
            CREATE TABLE `test` (
              `count` int NOT NULL DEFAULT '0',
              `price` decimal(10,2) NOT NULL DEFAULT '0.00'
            );
            SQL;

        $table = $this->parse($sql)->tables['test'];

        // Quoted defaults are parsed as strings, matching mysqldump output format
        $this->assertInstanceOf(SchemaColumnDefault::class, $table->columns['count']->default);
        $this->assertSame('0', $table->columns['count']->default->value);

        $this->assertInstanceOf(SchemaColumnDefault::class, $table->columns['price']->default);
        $this->assertSame('0.00', $table->columns['price']->default->value);
    }

    #[Test]
    public function it_handles_postgresql_typecast_defaults_as_unresolvable(): void
    {
        // pg_dump produces type-cast syntax like '{}'::json or 'now'::timestamp
        $sql = <<<'SQL'
            CREATE TABLE public.test (
                meta json DEFAULT '{}'::json,
                started_at timestamp DEFAULT 'now'::timestamp
            );
            SQL;

        $table = $this->parse($sql)->tables['test'];

        // Type-cast defaults are not statically resolvable
        $this->assertInstanceOf(SchemaColumnDefault::class, $table->columns['meta']->default);
        $this->assertFalse($table->columns['meta']->default->resolvable);

        $this->assertInstanceOf(SchemaColumnDefault::class, $table->columns['started_at']->default);
        $this->assertFalse($table->columns['started_at']->default->resolvable);
    }

    #[Test]
    public function it_handles_on_update_current_timestamp(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE `test` (
              `occurred_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `recorded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
            SQL;

        $table = $this->parse($sql)->tables['test'];

        $this->assertSame(SchemaColumn::TYPE_STRING, $table->columns['occurred_at']->type);
        $this->assertFalse($table->columns['occurred_at']->nullable);
        $this->assertInstanceOf(SchemaColumnDefault::class, $table->columns['occurred_at']->default);
        $this->assertFalse($table->columns['occurred_at']->default->resolvable);

        $this->assertInstanceOf(SchemaColumnDefault::class, $table->columns['recorded_at']->default);
        $this->assertFalse($table->columns['recorded_at']->default->resolvable);
    }

    #[Test]
    public function it_handles_generated_columns(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE `test` (
              `price` decimal(10,2) NOT NULL,
              `tax` decimal(10,2) GENERATED ALWAYS AS (`price` * 0.2) STORED
            );
            SQL;

        $table = $this->parse($sql)->tables['test'];

        // GENERATED is recognized as a modifier keyword, so the type is parsed correctly
        $this->assertSame(SchemaColumn::TYPE_FLOAT, $table->columns['tax']->type);
    }

    #[Test]
    public function it_parses_negative_numeric_defaults(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE `test` (
              `offset` int NOT NULL DEFAULT -1,
              `adjustment` decimal(5,2) NOT NULL DEFAULT -3.14
            );
            SQL;

        $table = $this->parse($sql)->tables['test'];

        $this->assertInstanceOf(SchemaColumnDefault::class, $table->columns['offset']->default);
        $this->assertSame(-1, $table->columns['offset']->default->value);

        $this->assertInstanceOf(SchemaColumnDefault::class, $table->columns['adjustment']->default);
        $this->assertSame(-3.14, $table->columns['adjustment']->default->value);
    }

    #[Test]
    public function it_parses_binary_and_blob_types(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE `test` (
              `hash` binary(32) NOT NULL,
              `data` blob NOT NULL,
              `big_data` longblob NOT NULL,
              `file` varbinary(1024) NOT NULL
            );
            SQL;

        $table = $this->parse($sql)->tables['test'];
        $this->assertSame(SchemaColumn::TYPE_STRING, $table->columns['hash']->type);
        $this->assertSame(SchemaColumn::TYPE_STRING, $table->columns['data']->type);
        $this->assertSame(SchemaColumn::TYPE_STRING, $table->columns['big_data']->type);
        $this->assertSame(SchemaColumn::TYPE_STRING, $table->columns['file']->type);
    }

    #[Test]
    public function it_parses_postgresql_smallserial(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE public.test (
                id smallserial NOT NULL
            );
            SQL;

        $table = $this->parse($sql)->tables['test'];
        $this->assertSame(SchemaColumn::TYPE_INT, $table->columns['id']->type);
    }

    #[Test]
    public function it_parses_unquoted_float_default(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE `test` (
              `rate` decimal(5,2) NOT NULL DEFAULT 3.14
            );
            SQL;

        $column = $this->parse($sql)->tables['test']->columns['rate'];
        $this->assertInstanceOf(SchemaColumnDefault::class, $column->default);
        $this->assertEqualsWithDelta(3.14, $column->default->value, PHP_FLOAT_EPSILON);
    }

    #[Test]
    public function it_overwrites_table_on_duplicate_create_table(): void
    {
        $sql = <<<'SQL'
            CREATE TABLE `users` (
              `id` bigint unsigned NOT NULL,
              `name` varchar(255) NOT NULL,
              `old_col` varchar(255) NOT NULL
            );
            CREATE TABLE `users` (
              `id` bigint unsigned NOT NULL,
              `email` varchar(255) NOT NULL
            );
            SQL;

        $table = $this->parse($sql)->tables['users'];

        // Second CREATE TABLE wins — overwrites the first
        $this->assertCount(2, $table->columns);
        $this->assertArrayHasKey('id', $table->columns);
        $this->assertArrayHasKey('email', $table->columns);
        $this->assertArrayNotHasKey('name', $table->columns);
    }

    // ──────────────────────────────────────────────────
    // Malformed SQL resilience
    // ──────────────────────────────────────────────────

    #[Test]
    public function it_handles_unbalanced_parentheses_gracefully(): void
    {
        // Truncated SQL — opening paren never closed
        $sql = <<<'SQL'
            CREATE TABLE `broken` (
              `id` bigint unsigned NOT NULL,
              `name` varchar(255) NOT NULL
            SQL;

        $schema = $this->parse($sql);

        // extractParenBody returns null → table skipped, no crash
        $this->assertCount(0, $schema->tables);
    }

    #[Test]
    public function it_skips_malformed_table_and_continues_parsing(): void
    {
        // First table is malformed (unclosed), second is valid
        $sql = <<<'SQL'
            CREATE TABLE `broken` (
              `id` bigint unsigned NOT NULL
            CREATE TABLE `valid` (
              `id` bigint unsigned NOT NULL,
              `name` varchar(255) NOT NULL
            );
            SQL;

        $schema = $this->parse($sql);

        // Malformed table skipped, valid table parsed
        $this->assertArrayHasKey('valid', $schema->tables);
        $this->assertCount(2, $schema->tables['valid']->columns);
    }

    #[Test]
    public function it_handles_completely_empty_create_table(): void
    {
        $sql = "CREATE TABLE `empty` ();";

        $schema = $this->parse($sql);
        $this->assertArrayHasKey('empty', $schema->tables);
        $this->assertCount(0, $schema->tables['empty']->columns);
    }

    // ──────────────────────────────────────────────────
    // Integration: SQL dump tables can be altered by PHP migrations
    // ──────────────────────────────────────────────────

    #[Test]
    public function sql_dump_tables_can_be_modified_by_php_migration_operations(): void
    {
        // Simulate the combined flow: SQL dump creates base state,
        // then PHP migration operations modify it
        $sql = <<<'SQL'
            CREATE TABLE `users` (
              `id` bigint unsigned NOT NULL AUTO_INCREMENT,
              `name` varchar(255) NOT NULL,
              `old_column` varchar(255) NOT NULL
            );
            SQL;

        $aggregator = new SchemaAggregator();
        $this->parser->addToAggregator($sql, $aggregator);

        // The SQL dump created the base table
        $this->assertCount(3, $aggregator->tables['users']->columns);

        // Simulate what SchemaAggregator would do when processing a PHP migration:
        // add a new column and drop an old one
        $aggregator->tables['users']->setColumn(
            new SchemaColumn('email', SchemaColumn::TYPE_STRING, false),
        );
        $aggregator->tables['users']->dropColumn('old_column');

        // Verify the combined state: original columns + modifications
        $this->assertCount(3, $aggregator->tables['users']->columns);
        $this->assertArrayHasKey('id', $aggregator->tables['users']->columns);
        $this->assertArrayHasKey('name', $aggregator->tables['users']->columns);
        $this->assertArrayHasKey('email', $aggregator->tables['users']->columns);
        $this->assertArrayNotHasKey('old_column', $aggregator->tables['users']->columns);
    }
}
