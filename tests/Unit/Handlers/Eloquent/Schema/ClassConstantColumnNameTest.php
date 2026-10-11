<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;

/**
 * Column-name arguments resolve class constants like table names do, and
 * foreignUuidFor()/foreignUlidFor() register their FK column.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1671
 */
#[CoversClass(SchemaAggregator::class)]
final class ClassConstantColumnNameTest extends AbstractSchemaAggregatorTestCase
{
    private const HEADER = <<<'PHP'
        <?php
        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;
        use Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema\Fixtures\ClassWithColumnConstants as Col;
        use Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema\Fixtures\ColumnNameEnum;

        PHP;

    #[Test]
    public function it_registers_column_defined_with_class_constant_name(): void
    {
        $schema = $this->schemaFromMigration(self::HEADER . <<<'PHP'
            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('posts', function (Blueprint $table): void {
                        $table->id();
                        $table->string(Col::TITLE);
                    });
                }
            };
            PHP);

        $this->assertSchemaHasTableAndNotNullableColumnOfType('posts.title', 'string', $schema);
    }

    #[Test]
    public function it_drops_columns_listed_by_class_constant(): void
    {
        $schema = $this->schemaFromMigration(self::HEADER . <<<'PHP'
            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('posts', function (Blueprint $table): void {
                        $table->id();
                        $table->string('title');
                        $table->string('body');
                        $table->string('slug');
                    });

                    Schema::table('posts', function (Blueprint $table): void {
                        $table->dropColumn([Col::TITLE]);
                    });

                    Schema::dropColumns('posts', [Col::TITLE, 'body']);
                    Schema::dropColumns('posts', Col::OLD);
                }
            };
            PHP);

        $this->assertSchemaHasTableAndNotNullableColumnOfType('posts.slug', 'string', $schema);
        $this->assertArrayNotHasKey('title', $schema->tables['posts']->columns);
        $this->assertArrayNotHasKey('body', $schema->tables['posts']->columns);
    }

    #[Test]
    public function it_renames_column_with_class_constant_arguments(): void
    {
        $schema = $this->schemaFromMigration(self::HEADER . <<<'PHP'
            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('posts', function (Blueprint $table): void {
                        $table->string('old_name');
                    });

                    Schema::table('posts', function (Blueprint $table): void {
                        $table->renameColumn(Col::OLD, Col::NEW);
                    });
                }
            };
            PHP);

        $this->assertSchemaHasTableAndNotNullableColumnOfType('posts.new_name', 'string', $schema);
        $this->assertArrayNotHasKey('old_name', $schema->tables['posts']->columns);
    }

    #[Test]
    public function it_resolves_class_constant_column_name_in_add_column(): void
    {
        $schema = $this->schemaFromMigration(self::HEADER . <<<'PHP'
            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('posts', function (Blueprint $table): void {
                        $table->addColumn('string', Col::TITLE);
                    });
                }
            };
            PHP);

        $this->assertSchemaHasTableAndNotNullableColumnOfType('posts.title', 'string', $schema);
    }

    #[Test]
    public function it_ignores_non_string_constants_and_enum_cases(): void
    {
        $schema = $this->schemaFromMigration(self::HEADER . <<<'PHP'
            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('posts', function (Blueprint $table): void {
                        $table->id();
                        $table->string(Col::NOT_A_STRING);
                        $table->string(ColumnNameEnum::Title);
                        $table->string(Missing::NAME);
                        $table->renameColumn('id', Col::NOT_A_STRING);
                        $table->dropColumn([ColumnNameEnum::Title, Col::NOT_A_STRING]);
                    });
                }
            };
            PHP);

        $this->assertSame(['id'], \array_keys($schema->tables['posts']->columns));
    }

    #[Test]
    public function foreign_uuid_for_and_foreign_ulid_for_register_string_columns(): void
    {
        $schema = $this->schemaFromMigration(self::HEADER . <<<'PHP'
            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('comments', function (Blueprint $table): void {
                        // Customer has an int PK: the FK is still a uuid/ulid string column.
                        $table->foreignUuidFor(\App\Models\Customer::class);
                        $table->foreignUlidFor(\App\Models\UuidModel::class);
                        $table->foreignUuidFor(\App\Models\Customer::class, Col::TITLE)->nullable();
                        $table->foreignUlidFor(\App\Models\Customer::class, 'reviewer_id');
                        // A string literal is a class name for Laravel, never a column.
                        $table->foreignUuidFor('App\Models\Customer');
                    });
                }
            };
            PHP);

        $table = $schema->tables['comments'];
        self::assertTableHasNotNullableColumnOfType('customer_id', 'string', $table);
        self::assertTableHasNotNullableColumnOfType('uuid_model_id', 'string', $table);
        self::assertTableHasNullableColumnOfType('title', 'string', $table);
        self::assertTableHasNotNullableColumnOfType('reviewer_id', 'string', $table);
        $this->assertFalse($table->columns['customer_id']->unsigned);
        $this->assertCount(4, $table->columns);
    }

    #[Test]
    public function foreign_uuid_for_with_unresolvable_class_registers_nothing(): void
    {
        $schema = $this->schemaFromMigration(self::HEADER . <<<'PHP'
            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('comments', function (Blueprint $table): void {
                        $table->id();
                        $table->foreignUuidFor(Missing::class);
                        $table->foreignUlidFor(Col::TITLE);
                    });
                }
            };
            PHP);

        $this->assertSame(['id'], \array_keys($schema->tables['comments']->columns));
    }

    #[Test]
    public function unresolved_explicit_column_argument_never_falls_back_to_the_default_name(): void
    {
        $schema = $this->schemaFromMigration(self::HEADER . <<<'PHP'
            return new class extends Migration {
                private const REVIEWER = 'reviewer_id';

                public function up(): void
                {
                    $column = 'dynamic_id';

                    Schema::create('comments', function (Blueprint $table) use ($column): void {
                        $table->integer('customer_id');
                        $table->foreignUuidFor(\App\Models\Customer::class, self::REVIEWER);
                        $table->foreignUlidFor(\App\Models\Customer::class, $column);
                        $table->addColumn('string', self::REVIEWER);
                        $table->string('old_name');
                        $table->renameColumn('old_name', self::REVIEWER);
                    });

                    // foreignIdFor would re-type customer_id as int, so it gets its own table.
                    Schema::create('likes', function (Blueprint $table): void {
                        $table->string('customer_id');
                        $table->foreignIdFor(\App\Models\Customer::class, self::REVIEWER);
                        $table->foreignIdFor(\App\Models\Customer::class, strtolower('X'));
                    });
                }
            };
            PHP);

        $table = $schema->tables['comments'];
        self::assertTableHasNotNullableColumnOfType('customer_id', 'int', $table);
        $this->assertSame(['customer_id', 'old_name'], \array_keys($table->columns));

        $likes = $schema->tables['likes'];
        self::assertTableHasNotNullableColumnOfType('customer_id', 'string', $likes);
        $this->assertSame(['customer_id'], \array_keys($likes->columns));
    }

    #[Test]
    public function foreign_for_with_literal_null_column_uses_the_conventional_name(): void
    {
        $schema = $this->schemaFromMigration(self::HEADER . <<<'PHP'
            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('comments', function (Blueprint $table): void {
                        $table->foreignUuidFor(\App\Models\Customer::class, null);
                    });
                }
            };
            PHP);

        self::assertTableHasNotNullableColumnOfType('customer_id', 'string', $schema->tables['comments']);
    }
}
