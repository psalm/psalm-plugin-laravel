<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;

/**
 * Psalm's name resolver skips arrow function signatures, so SchemaAggregator::isForeignParamType()
 * judges them by the written class name. See its docblock for the upstream fix to wait for.
 */
#[CoversClass(SchemaAggregator::class)]
final class ArrowFunctionBlueprintAliasKnownLimitationTest extends AbstractSchemaAggregatorTestCase
{
    #[Test]
    public function foreign_class_named_blueprint_is_treated_as_blueprint(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Foo\Blueprint;
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('tags', fn (Blueprint $table) => $table->string('a'));
                }
            };
            PHP);

        // Ideal behavior: declined, as the closure form is.
        $this->assertArrayHasKey('tags', $schema->tables);
    }

    #[Test]
    public function aliased_blueprint_loses_the_whole_table(): void
    {
        $schema = $this->schemaFromMigration(<<<'PHP'
            <?php
            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint as B;
            use Illuminate\Support\Facades\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::create('tags', fn (B $table) => $table->string('a'));
                }
            };
            PHP);

        // Ideal behavior: the table is registered with column `a`, as the closure form does.
        $this->assertArrayNotHasKey('tags', $schema->tables);
    }
}
