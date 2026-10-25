<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\Test;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;

#[\PHPUnit\Framework\Attributes\CoversClass(\Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator::class)]
final class DefaultValuesTest extends AbstractSchemaAggregatorTestCase
{
    private SchemaAggregator $schemaAggregator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schemaAggregator = $this->instantiateSchemaAggregator(
            __DIR__ . '/migrations/default_values',
        );
    }

    #[Test]
    public function it_extracts_literal_defaults_by_type(): void
    {
        $columns = $this->schemaAggregator->tables['products']->columns;

        $this->assertColumnHasDefault('draft', $columns['status']);
        $this->assertColumnHasDefault(0, $columns['quantity']);
        $this->assertColumnHasDefault(9.99, $columns['price']);
        $this->assertColumnHasDefault(true, $columns['active']);
        $this->assertColumnHasDefault(false, $columns['featured']);
        $this->assertColumnHasDefault(-1, $columns['negative']);
        $this->assertColumnHasDefault(-0.5, $columns['discount']);

        $this->assertColumnHasDefault(null, $columns['description']);
        $this->assertColumnNullable($columns['description']);

        $this->assertColumnHasNoDefault($columns['no_default']);
    }

    /**
     * Non-resolvable expressions (e.g. new Expression('NOW()')) are tracked as
     * having a default, but marked as unresolvable — distinct from ->default(null).
     */
    #[Test]
    public function it_marks_non_resolvable_expression_as_unresolvable(): void
    {
        $column = $this->schemaAggregator->tables['products']->columns['published_at'];

        $this->assertColumnHasUnresolvableDefault($column);
    }
}
