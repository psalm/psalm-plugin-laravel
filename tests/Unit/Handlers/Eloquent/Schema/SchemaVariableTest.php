<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;

/**
 * Tests that a schema builder assigned to a local variable is recognized, e.g.:
 *
 *   $schema = Schema::connection($this->getConnection());
 *   $schema->create('table', ...);
 *
 * This shape is published by Laravel Telescope's migrations.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1377
 */
#[CoversClass(SchemaAggregator::class)]
final class SchemaVariableTest extends AbstractSchemaAggregatorTestCase
{
    #[Test]
    public function it_detects_columns_from_variable_assigned_schema_builder(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    $schema = Schema::connection($this->getConnection());
                    $schema->create('telescope_entries', function (Blueprint $table): void {
                        $table->id();
                        $table->string('type');
                    });
                }

                public function getConnection(): ?string
                {
                    return null;
                }
            };
            PHP);

        $this->assertArrayHasKey('telescope_entries', $schema->tables);
        $this->assertSchemaHasTableAndNotNullableColumnOfType('telescope_entries.id', 'int', $schema);
        $this->assertSchemaHasTableAndNotNullableColumnOfType('telescope_entries.type', 'string', $schema);
    }

    #[Test]
    public function it_detects_columns_from_variable_assigned_custom_schema_facade(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema\Fixtures\CustomSchema;

            return new class extends Migration {
                public function up(): void
                {
                    $schema = CustomSchema::connection('pgsql');
                    $schema->create('posts', function (Blueprint $table): void {
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
    public function it_routes_table_drop_rename_and_drop_columns_through_a_variable(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    $schema = Schema::connection($this->getConnection());
                    $schema->create('telescope_entries', function (Blueprint $table): void {
                        $table->id();
                        $table->string('type');
                        $table->string('scratch');
                    });
                    $schema->table('telescope_entries', function (Blueprint $table): void {
                        $table->string('email')->nullable();
                    });
                    $schema->dropColumns('telescope_entries', 'scratch');
                    $schema->rename('telescope_entries', 'telescope_entries_renamed');

                    $schema->create('telescope_monitoring', function (Blueprint $table): void {
                        $table->string('tag');
                    });
                    $schema->dropIfExists('telescope_monitoring');
                }

                public function getConnection(): ?string
                {
                    return null;
                }
            };
            PHP);

        $this->assertArrayNotHasKey('telescope_entries', $schema->tables);
        $this->assertArrayHasKey('telescope_entries_renamed', $schema->tables);
        $this->assertSchemaHasTableAndNotNullableColumnOfType('telescope_entries_renamed.type', 'string', $schema);
        $this->assertSchemaHasTableAndNullableColumnOfType('telescope_entries_renamed.email', 'string', $schema);
        $this->assertArrayNotHasKey('scratch', $schema->tables['telescope_entries_renamed']->columns);

        $this->assertArrayNotHasKey('telescope_monitoring', $schema->tables);
    }

    #[Test]
    public function it_tracks_a_variable_assigned_inside_a_conditional(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    if (true) {
                        $schema = Schema::connection($this->getConnection());
                    }

                    $schema->create('telescope_entries', function (Blueprint $table): void {
                        $table->id();
                    });
                }

                public function getConnection(): ?string
                {
                    return null;
                }
            };
            PHP);

        $this->assertArrayHasKey('telescope_entries', $schema->tables);
        $this->assertSchemaHasTableAndNotNullableColumnOfType('telescope_entries.id', 'int', $schema);
    }

    #[Test]
    public function it_ignores_calls_on_an_untracked_variable(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;

            return new class extends Migration {
                public function up(): void
                {
                    $schema->create('telescope_entries', function (Blueprint $table): void {
                        $table->id();
                    });
                }
            };
            PHP);

        $this->assertArrayNotHasKey('telescope_entries', $schema->tables);
    }

    #[Test]
    public function it_untracks_a_variable_reassigned_to_a_non_schema_value(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    $schema = Schema::connection($this->getConnection());
                    $schema = new \stdClass();
                    $schema->create('telescope_entries', function (Blueprint $table): void {
                        $table->id();
                    });
                }

                public function getConnection(): ?string
                {
                    return null;
                }
            };
            PHP);

        $this->assertArrayNotHasKey('telescope_entries', $schema->tables);
    }

    #[Test]
    public function it_untracks_a_variable_rebound_by_foreach(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    $schema = Schema::connection($this->getConnection());

                    foreach (['a', 'b'] as $schema) {
                        // rebinds $schema to a plain string on each iteration
                    }

                    $schema->create('telescope_entries', function (Blueprint $table): void {
                        $table->id();
                    });
                }

                public function getConnection(): ?string
                {
                    return null;
                }
            };
            PHP);

        $this->assertArrayNotHasKey('telescope_entries', $schema->tables);
    }

    #[Test]
    public function it_does_not_leak_tracking_between_methods(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    $this->setupA();
                    $this->setupB();
                }

                private function setupA(): void
                {
                    $schema = Schema::connection($this->getConnection());
                    $schema->create('telescope_entries', function (Blueprint $table): void {
                        $table->id();
                    });
                }

                private function setupB(): void
                {
                    // $schema here was never assigned a schema builder in THIS method's
                    // scope — tracking must not leak in from setupA().
                    $schema->create('telescope_monitoring', function (Blueprint $table): void {
                        $table->id();
                    });
                }

                public function getConnection(): ?string
                {
                    return null;
                }
            };
            PHP);

        $this->assertArrayHasKey('telescope_entries', $schema->tables);
        $this->assertArrayNotHasKey('telescope_monitoring', $schema->tables);
    }

    #[Test]
    public function it_untracks_a_variable_mutated_by_compound_assignment(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    $schema = Schema::connection($this->getConnection());
                    $schema .= 'x';

                    $schema->create('telescope_entries', function (Blueprint $table): void {
                        $table->id();
                    });
                }

                public function getConnection(): ?string
                {
                    return null;
                }
            };
            PHP);

        $this->assertArrayNotHasKey('telescope_entries', $schema->tables);
    }

    #[Test]
    public function it_untracks_a_variable_rebound_by_reference(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    $schema = Schema::connection($this->getConnection());
                    $other = new \stdClass();
                    $schema = &$other;

                    $schema->create('telescope_entries', function (Blueprint $table): void {
                        $table->id();
                    });
                }

                public function getConnection(): ?string
                {
                    return null;
                }
            };
            PHP);

        $this->assertArrayNotHasKey('telescope_entries', $schema->tables);
    }

    #[Test]
    public function it_ignores_filament_schema_make_variable(): void
    {
        // Filament\Schemas\Schema::make() is an unrelated builder that also uses the
        // `Schema` class name; `Schema::make(...)` (no `connection()`) must never be
        // mistaken for Illuminate\Support\Facades\Schema::connection(...).
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    $schema = Schema::make('x');
                    $schema->create('should_not_exist', function (Blueprint $table): void {
                        $table->id();
                    });
                }
            };
            PHP);

        $this->assertArrayNotHasKey('should_not_exist', $schema->tables);
    }

    #[Test]
    public function it_ignores_connection_call_on_a_non_schema_class(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;

            return new class extends Migration {
                public function up(): void
                {
                    $schema = \Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema\Fixtures\NotASchemaFacade::connection('x');
                    $schema->create('telescope_entries', function (Blueprint $table): void {
                        $table->id();
                    });
                }
            };
            PHP);

        $this->assertArrayNotHasKey('telescope_entries', $schema->tables);
    }
}
