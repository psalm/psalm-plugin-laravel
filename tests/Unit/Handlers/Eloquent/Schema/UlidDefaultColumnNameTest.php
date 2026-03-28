<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;

#[CoversClass(SchemaAggregator::class)]
final class UlidDefaultColumnNameTest extends AbstractSchemaAggregatorTestCase
{
    #[Test]
    public function ulid_without_args_defaults_to_ulid_column(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('posts', static function (Blueprint $table) {
                        $table->ulid();
                    });
                }
            };
            PHP);

        $this->assertTableHasNotNullableColumnOfType('ulid', 'string', $schema->tables['posts']);
    }

    #[Test]
    public function ulid_with_custom_name(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('posts', static function (Blueprint $table) {
                        $table->ulid('custom_ulid');
                    });
                }
            };
            PHP);

        $this->assertTableHasNotNullableColumnOfType('custom_ulid', 'string', $schema->tables['posts']);
    }

    #[Test]
    public function uuid_default_column_name_unchanged(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('posts', static function (Blueprint $table) {
                        $table->uuid();
                    });
                }
            };
            PHP);

        $this->assertTableHasNotNullableColumnOfType('uuid', 'string', $schema->tables['posts']);
    }
}
