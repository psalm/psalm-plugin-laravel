<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;

#[CoversClass(SchemaAggregator::class)]
final class ArrowFunctionCallbackTest extends AbstractSchemaAggregatorTestCase
{
    #[Test]
    public function schema_create_accepts_arrow_function(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('tags', fn (Blueprint $table) => $table->string('name')->nullable());
                }
            };
            PHP);

        $this->assertTableHasNullableColumnOfType('name', 'string', $schema->tables['tags']);
    }

    #[Test]
    public function schema_table_accepts_static_arrow_function(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('tags', static function (Blueprint $table) {
                        $table->id();
                    });

                    Schema::table('tags', static fn (Blueprint $table) => $table->integer('weight'));
                }
            };
            PHP);

        $table = $schema->tables['tags'];
        $this->assertTableHasColumn('id', $table);
        $this->assertTableHasNotNullableColumnOfType('weight', 'int', $table);
    }

    #[Test]
    public function after_accepts_arrow_function(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('tags', function (Blueprint $table) {
                        $table->string('name');
                        $table->after('name', fn (Blueprint $table) => $table->string('slug'));
                    });
                }
            };
            PHP);

        $this->assertTableHasNotNullableColumnOfType('slug', 'string', $schema->tables['tags']);
    }

    #[Test]
    public function arrow_function_without_parameter_is_declined(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('tags', fn () => 1);
                }
            };
            PHP);

        $this->assertArrayNotHasKey('tags', $schema->tables);
    }
}
