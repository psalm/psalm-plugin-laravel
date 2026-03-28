<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;

/**
 * Tests that class constant table names (e.g. Schema::create(User::TABLE, ...)) are resolved.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/522
 */
#[CoversClass(SchemaAggregator::class)]
final class ClassConstantTableNameTest extends AbstractSchemaAggregatorTestCase
{
    #[Test]
    public function it_resolves_class_constant_table_name_in_create(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;
            use Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema\Fixtures\ClassWithTableConstant;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create(ClassWithTableConstant::TABLE, function (Blueprint $table): void {
                        $table->id();
                        $table->string('name');
                    });
                }
            };
            PHP);

        $this->assertArrayHasKey('users', $schema->tables);
        $this->assertSchemaHasTableAndNotNullableColumnOfType('users.id', 'int', $schema);
        $this->assertSchemaHasTableAndNotNullableColumnOfType('users.name', 'string', $schema);
    }

    #[Test]
    public function it_resolves_class_constant_table_name_in_table(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;
            use Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema\Fixtures\ClassWithTableConstant;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('users', function (Blueprint $table): void {
                        $table->id();
                    });

                    Schema::table(ClassWithTableConstant::TABLE, function (Blueprint $table): void {
                        $table->string('email');
                    });
                }
            };
            PHP);

        $this->assertSchemaHasTableAndNotNullableColumnOfType('users.id', 'int', $schema);
        $this->assertSchemaHasTableAndNotNullableColumnOfType('users.email', 'string', $schema);
    }

    #[Test]
    public function it_resolves_different_class_constants_from_same_class(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;
            use Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema\Fixtures\ClassWithTableConstant;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create(ClassWithTableConstant::TABLE, function (Blueprint $table): void {
                        $table->id();
                    });

                    Schema::create(ClassWithTableConstant::POSTS_TABLE, function (Blueprint $table): void {
                        $table->id();
                        $table->string('title');
                    });
                }
            };
            PHP);

        $this->assertArrayHasKey('users', $schema->tables);
        $this->assertArrayHasKey('posts', $schema->tables);
        $this->assertSchemaHasTableAndNotNullableColumnOfType('posts.title', 'string', $schema);
    }

    #[Test]
    public function it_ignores_unresolvable_class_constants(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create(NonExistentClass::TABLE, function (Blueprint $table): void {
                        $table->id();
                    });
                }
            };
            PHP);

        $this->assertEmpty($schema->tables);
    }

    #[Test]
    public function it_handles_class_constant_in_drop(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;
            use Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema\Fixtures\ClassWithTableConstant;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('users', function (Blueprint $table): void {
                        $table->id();
                    });

                    Schema::dropIfExists(ClassWithTableConstant::TABLE);
                }
            };
            PHP);

        $this->assertArrayNotHasKey('users', $schema->tables);
    }

    #[Test]
    public function it_handles_class_constant_in_rename(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;
            use Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema\Fixtures\ClassWithTableConstant;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('users', function (Blueprint $table): void {
                        $table->id();
                        $table->string('name');
                    });

                    Schema::rename(ClassWithTableConstant::TABLE, 'members');
                }
            };
            PHP);

        $this->assertArrayNotHasKey('users', $schema->tables);
        $this->assertArrayHasKey('members', $schema->tables);
        $this->assertSchemaHasTableAndNotNullableColumnOfType('members.name', 'string', $schema);
    }

    #[Test]
    public function it_handles_class_constant_in_drop_columns(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;
            use Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema\Fixtures\ClassWithTableConstant;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('users', function (Blueprint $table): void {
                        $table->id();
                        $table->string('name');
                        $table->string('email');
                    });

                    Schema::dropColumns(ClassWithTableConstant::TABLE, 'email');
                }
            };
            PHP);

        $this->assertArrayHasKey('users', $schema->tables);
        $this->assertSchemaHasTableAndNotNullableColumnOfType('users.name', 'string', $schema);
        $this->assertArrayNotHasKey('email', $schema->tables['users']->columns);
    }

    #[Test]
    public function it_resolves_class_constant_as_second_rename_argument(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;
            use Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema\Fixtures\ClassWithTableConstant;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('users', function (Blueprint $table): void {
                        $table->id();
                    });

                    Schema::rename('users', ClassWithTableConstant::POSTS_TABLE);
                }
            };
            PHP);

        $this->assertArrayNotHasKey('users', $schema->tables);
        $this->assertArrayHasKey('posts', $schema->tables);
    }

    #[Test]
    public function it_ignores_non_string_class_constants(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;
            use Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema\Fixtures\ClassWithTableConstant;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create(ClassWithTableConstant::NOT_A_STRING, function (Blueprint $table): void {
                        $table->id();
                    });
                }
            };
            PHP);

        $this->assertEmpty($schema->tables);
    }
}
