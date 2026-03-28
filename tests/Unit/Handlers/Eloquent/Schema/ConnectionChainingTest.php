<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;

/**
 * Tests that Schema::connection('mysql')->create/table/drop calls are recognized.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/522
 */
#[CoversClass(SchemaAggregator::class)]
final class ConnectionChainingTest extends AbstractSchemaAggregatorTestCase
{
    #[Test]
    public function it_detects_columns_from_connection_chained_create(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::connection('mysql')->create('users', function (Blueprint $table): void {
                        $table->id();
                        $table->string('name');
                        $table->string('email');
                        $table->timestamps();
                    });
                }
            };
            PHP);

        $this->assertArrayHasKey('users', $schema->tables);
        $this->assertSchemaHasTableAndNotNullableColumnOfType('users.id', 'int', $schema);
        $this->assertSchemaHasTableAndNotNullableColumnOfType('users.name', 'string', $schema);
        $this->assertSchemaHasTableAndNotNullableColumnOfType('users.email', 'string', $schema);
        $this->assertSchemaHasTableAndNullableColumnOfType('users.created_at', 'string', $schema);
    }

    #[Test]
    public function it_detects_columns_from_connection_chained_table(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::connection('mysql')->create('users', function (Blueprint $table): void {
                        $table->id();
                    });

                    Schema::connection('mysql')->table('users', function (Blueprint $table): void {
                        $table->string('email');
                    });
                }
            };
            PHP);

        $this->assertSchemaHasTableAndNotNullableColumnOfType('users.id', 'int', $schema);
        $this->assertSchemaHasTableAndNotNullableColumnOfType('users.email', 'string', $schema);
    }

    #[Test]
    public function it_handles_connection_chained_drop(): void
    {
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
                    });

                    Schema::connection('mysql')->dropIfExists('users');
                }
            };
            PHP);

        $this->assertArrayNotHasKey('users', $schema->tables);
    }

    #[Test]
    public function it_handles_connection_chained_rename(): void
    {
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

                    Schema::connection('mysql')->rename('users', 'members');
                }
            };
            PHP);

        $this->assertArrayNotHasKey('users', $schema->tables);
        $this->assertArrayHasKey('members', $schema->tables);
        $this->assertSchemaHasTableAndNotNullableColumnOfType('members.name', 'string', $schema);
    }

    #[Test]
    public function it_handles_connection_chained_drop_columns(): void
    {
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
                        $table->string('email');
                    });

                    Schema::connection('mysql')->dropColumns('users', 'email');
                }
            };
            PHP);

        $this->assertArrayHasKey('users', $schema->tables);
        $this->assertSchemaHasTableAndNotNullableColumnOfType('users.name', 'string', $schema);
        $this->assertArrayNotHasKey('email', $schema->tables['users']->columns);
    }
}
