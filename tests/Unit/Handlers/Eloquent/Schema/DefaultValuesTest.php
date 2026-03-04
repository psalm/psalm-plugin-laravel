<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;

/** @covers \Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator */
final class DefaultValuesTest extends AbstractSchemaAggregatorTestCase
{
    private SchemaAggregator $schemaAggregator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schemaAggregator = $this->instantiateSchemaAggregator(
            __DIR__ . '/migrations/default_values'
        );
    }

    public function test_it_extracts_string_default(): void
    {
        $column = $this->schemaAggregator->tables['products']->columns['status'];

        $this->assertColumnHasDefault('draft', $column);
    }

    public function test_it_extracts_integer_default(): void
    {
        $column = $this->schemaAggregator->tables['products']->columns['quantity'];

        $this->assertColumnHasDefault(0, $column);
    }

    public function test_it_extracts_float_default(): void
    {
        $column = $this->schemaAggregator->tables['products']->columns['price'];

        $this->assertColumnHasDefault(9.99, $column);
    }

    public function test_it_extracts_boolean_true_default(): void
    {
        $column = $this->schemaAggregator->tables['products']->columns['active'];

        $this->assertColumnHasDefault(true, $column);
    }

    public function test_it_extracts_boolean_false_default(): void
    {
        $column = $this->schemaAggregator->tables['products']->columns['featured'];

        $this->assertColumnHasDefault(false, $column);
    }

    public function test_it_extracts_null_default(): void
    {
        $column = $this->schemaAggregator->tables['products']->columns['description'];

        $this->assertColumnHasDefault(null, $column);
        $this->assertColumnNullable($column);
    }

    public function test_it_extracts_negative_integer_default(): void
    {
        $column = $this->schemaAggregator->tables['products']->columns['negative'];

        $this->assertColumnHasDefault(-1, $column);
    }

    public function test_it_extracts_negative_float_default(): void
    {
        $column = $this->schemaAggregator->tables['products']->columns['discount'];

        $this->assertColumnHasDefault(-0.5, $column);
    }

    public function test_it_detects_column_without_default(): void
    {
        $column = $this->schemaAggregator->tables['products']->columns['no_default'];

        $this->assertColumnHasNoDefault($column);
    }

    public function test_it_detects_column_without_default_for_name_column(): void
    {
        $column = $this->schemaAggregator->tables['products']->columns['name'];

        $this->assertColumnHasNoDefault($column);
    }

    /**
     * Non-resolvable expressions (e.g. new Expression('NOW()')) are tracked as
     * having a default, but the value falls back to null since it cannot be
     * statically resolved from the AST.
     */
    public function test_it_handles_non_resolvable_expression_default(): void
    {
        $column = $this->schemaAggregator->tables['products']->columns['published_at'];

        $this->assertColumnHasDefault(null, $column);
    }
}
