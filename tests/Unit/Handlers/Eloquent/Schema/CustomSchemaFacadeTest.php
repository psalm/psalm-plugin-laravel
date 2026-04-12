<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;

/**
 * Tests that custom Schema facade subclasses are recognized by the aggregator.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/522
 */
#[CoversClass(SchemaAggregator::class)]
final class CustomSchemaFacadeTest extends AbstractSchemaAggregatorTestCase
{
    #[Test]
    public function it_detects_columns_from_custom_schema_facade(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema\Fixtures\CustomSchema;

            return new class extends Migration {
                public function up(): void
                {
                    CustomSchema::create('users', function (Blueprint $table): void {
                        $table->id();
                        $table->string('name');
                        $table->string('email');
                    });
                }
            };
            PHP);

        $this->assertArrayHasKey('users', $schema->tables);
        $this->assertSchemaHasTableAndNotNullableColumnOfType('users.id', 'int', $schema);
        $this->assertSchemaHasTableAndNotNullableColumnOfType('users.name', 'string', $schema);
        $this->assertSchemaHasTableAndNotNullableColumnOfType('users.email', 'string', $schema);
    }

    #[Test]
    public function it_handles_custom_facade_with_connection_chaining(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema\Fixtures\CustomSchema;

            return new class extends Migration {
                public function up(): void
                {
                    CustomSchema::connection('pgsql')->create('posts', function (Blueprint $table): void {
                        $table->id();
                        $table->string('title');
                    });
                }
            };
            PHP);

        $this->assertArrayHasKey('posts', $schema->tables);
        $this->assertSchemaHasTableAndNotNullableColumnOfType('posts.id', 'int', $schema);
        $this->assertSchemaHasTableAndNotNullableColumnOfType('posts.title', 'string', $schema);
    }

    #[Test]
    public function it_handles_custom_facade_drop(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;
            use Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema\Fixtures\CustomSchema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('users', function (Blueprint $table): void {
                        $table->id();
                    });

                    CustomSchema::dropIfExists('users');
                }
            };
            PHP);

        $this->assertArrayNotHasKey('users', $schema->tables);
    }

    #[Test]
    public function it_still_detects_original_schema_facade(): void
    {
        // Ensure the existing behavior is preserved
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('users', function (Blueprint $table): void {
                        $table->id();
                        $table->string('name');
                    });
                }
            };
            PHP);

        $this->assertArrayHasKey('users', $schema->tables);
        $this->assertSchemaHasTableAndNotNullableColumnOfType('users.id', 'int', $schema);
    }
}
