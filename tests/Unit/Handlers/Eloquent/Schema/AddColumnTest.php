<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;

#[CoversClass(SchemaAggregator::class)]
final class AddColumnTest extends AbstractSchemaAggregatorTestCase
{
    #[Test]
    public function add_column_with_string_type(): void
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
                        $table->addColumn('string', 'email');
                    });
                }
            };
            PHP);

        $this->assertTableHasNotNullableColumnOfType('email', 'string', $schema->tables['users']);
    }

    #[Test]
    public function add_column_with_integer_type(): void
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
                        $table->addColumn('integer', 'quantity');
                    });
                }
            };
            PHP);

        $this->assertTableHasNotNullableColumnOfType('quantity', 'int', $schema->tables['products']);
    }

    #[Test]
    public function add_column_with_boolean_type(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('settings', static function (Blueprint $table) {
                        $table->id();
                        $table->addColumn('boolean', 'active');
                    });
                }
            };
            PHP);

        $this->assertTableHasNotNullableColumnOfType('active', 'bool', $schema->tables['settings']);
    }

    #[Test]
    public function add_column_with_nullable_modifier(): void
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
                        $table->addColumn('string', 'subtitle')->nullable();
                    });
                }
            };
            PHP);

        $this->assertTableHasNullableColumnOfType('subtitle', 'string', $schema->tables['posts']);
    }

    #[Test]
    public function add_column_with_ip_address_type(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('logs', static function (Blueprint $table) {
                        $table->id();
                        $table->addColumn('ipAddress', 'client_ip');
                    });
                }
            };
            PHP);

        $this->assertTableHasNotNullableColumnOfType('client_ip', 'string', $schema->tables['logs']);
    }

    #[Test]
    public function add_column_with_float_type(): void
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
                        $table->addColumn('decimal', 'price');
                    });
                }
            };
            PHP);

        $this->assertTableHasNotNullableColumnOfType('price', 'float', $schema->tables['products']);
    }
}
