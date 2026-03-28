<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;

/**
 * Unknown Blueprint methods (e.g. custom macros like Blueprint::macro('customType', ...))
 * should register a column with type 'mixed' instead of being silently skipped.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/527
 */
#[CoversClass(SchemaAggregator::class)]
final class UnknownBlueprintMethodTest extends AbstractSchemaAggregatorTestCase
{
    #[Test]
    public function unknown_method_registers_column_as_mixed(): void
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
                        $table->customType('payload');
                    });
                }
            };
            PHP);

        $this->assertTableHasNotNullableColumnOfType('payload', 'mixed', $schema->tables['orders']);
    }

    #[Test]
    public function unknown_method_respects_nullable(): void
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
                        $table->customType('payload')->nullable();
                    });
                }
            };
            PHP);

        $this->assertTableHasNullableColumnOfType('payload', 'mixed', $schema->tables['orders']);
    }

    #[Test]
    public function unknown_method_respects_default_value(): void
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
                        $table->customType('payload')->default('none');
                    });
                }
            };
            PHP);

        $table = $schema->tables['orders'];
        $this->assertTableHasNotNullableColumnOfType('payload', 'mixed', $table);
        $this->assertColumnHasDefault('none', $table->columns['payload']);
    }

    /**
     * Table-level property methods like engine(), charset(), collation() set table
     * properties — not columns. Without explicit handling, $table->engine('InnoDB')
     * would register 'InnoDB' as a column via the default case.
     */
    #[Test]
    public function table_level_methods_do_not_create_columns(): void
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
                        $table->engine('InnoDB');
                        $table->charset('utf8mb4');
                        $table->collation('utf8mb4_unicode_ci');
                        $table->comment('Order records');
                    });
                }
            };
            PHP);

        $table = $schema->tables['orders'];

        // Only the 'id' column should exist — table-level methods must not register columns
        $this->assertArrayHasKey('id', $table->columns);
        $this->assertCount(1, $table->columns);
    }

    /**
     * Index methods like fullText(), vectorIndex() take existing column names as arguments.
     * Without explicit handling, $table->fullText('title') after $table->string('title')
     * would overwrite the correct 'string' type with 'mixed' via the default case.
     */
    #[Test]
    public function index_methods_do_not_overwrite_column_types(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('articles', static function (Blueprint $table) {
                        $table->id();
                        $table->string('title');
                        $table->vector('embedding', 1536);
                        $table->fullText('title');
                        $table->vectorIndex('embedding');
                        $table->rawIndex('LOWER(title)', 'articles_title_lower_idx');
                    });
                }
            };
            PHP);

        $table = $schema->tables['articles'];

        // Column types must remain as originally defined — index methods must not overwrite them
        $this->assertTableHasNotNullableColumnOfType('title', 'string', $table);
        $this->assertTableHasNotNullableColumnOfType('embedding', 'array', $table);

        // rawIndex's expression argument must not register as a column
        $this->assertArrayNotHasKey('LOWER(title)', $table->columns);
    }
}
