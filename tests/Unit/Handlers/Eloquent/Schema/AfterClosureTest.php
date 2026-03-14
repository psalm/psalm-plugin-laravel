<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;

#[CoversClass(SchemaAggregator::class)]
final class AfterClosureTest extends AbstractSchemaAggregatorTestCase
{
    #[Test]
    public function columns_inside_after_closure_are_discovered(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('users', static function (Blueprint $table) {
                        $table->id();
                        $table->string('email');
                        $table->after('email', static function (Blueprint $table) {
                            $table->string('address');
                            $table->string('city');
                            $table->string('state');
                        });
                    });
                }
            };
            PHP);

        $table = $schema->tables['users'];
        $this->assertTableHasNotNullableColumnOfType('address', 'string', $table);
        $this->assertTableHasNotNullableColumnOfType('city', 'string', $table);
        $this->assertTableHasNotNullableColumnOfType('state', 'string', $table);
    }

    #[Test]
    public function after_closure_with_different_parameter_name(): void
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
                        $table->id();
                        $table->after('id', static function (Blueprint $blueprint) {
                            $blueprint->string('title');
                            $blueprint->boolean('published');
                        });
                    });
                }
            };
            PHP);

        $table = $schema->tables['posts'];
        $this->assertTableHasNotNullableColumnOfType('title', 'string', $table);
        $this->assertTableHasNotNullableColumnOfType('published', 'bool', $table);
    }

    #[Test]
    public function after_closure_with_nullable_and_default_modifiers(): void
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
                        $table->after('id', static function (Blueprint $table) {
                            $table->string('status')->nullable()->default('pending');
                            $table->integer('quantity')->unsigned();
                        });
                    });
                }
            };
            PHP);

        $table = $schema->tables['orders'];
        $this->assertTableHasNullableColumnOfType('status', 'string', $table);
        $this->assertColumnHasDefault('pending', $table->columns['status']);
        $this->assertTableHasNotNullableColumnOfType('quantity', 'int', $table);
        $this->assertTrue($table->columns['quantity']->unsigned);
    }

    #[Test]
    public function after_closure_in_alter_table(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('products', static function (Blueprint $table) {
                        $table->id();
                        $table->string('name');
                    });

                    Schema::table('products', static function (Blueprint $table) {
                        $table->after('name', static function (Blueprint $table) {
                            $table->decimal('price');
                            $table->text('description')->nullable();
                        });
                    });
                }
            };
            PHP);

        $table = $schema->tables['products'];
        $this->assertTableHasNotNullableColumnOfType('price', 'float', $table);
        $this->assertTableHasNullableColumnOfType('description', 'string', $table);
    }
}
