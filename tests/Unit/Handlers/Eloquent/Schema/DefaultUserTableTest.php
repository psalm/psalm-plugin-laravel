<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

/** @covers \Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator */
final class DefaultUserTableTest extends AbstractSchemaAggregatorTest
{
    /** @test */
    public function it_detects_all_columns(): void
    {
        $schemaAggregator = $this->instantiateSchemaAggregator(__DIR__.'/migrations/simple');

        $this->assertSchemaHasTableAndNotNullableColumnOfType('users.id', 'int', $schemaAggregator);
        $this->assertSchemaHasTableAndNotNullableColumnOfType('users.email', 'string', $schemaAggregator);
        $this->assertSchemaHasTableAndNotNullableColumnOfType('users.password', 'string', $schemaAggregator);
        $this->assertSchemaHasTableAndNotNullableColumnOfType('users.password', 'string', $schemaAggregator);
        $this->assertSchemaHasTableAndNotNullableColumnOfType('users.remember_token', 'string', $schemaAggregator);
        $this->assertSchemaHasTableAndNullableColumnOfType('users.email_verified_at', 'string', $schemaAggregator);
        $this->assertSchemaHasTableAndNullableColumnOfType('users.created_at', 'string', $schemaAggregator);
        $this->assertSchemaHasTableAndNullableColumnOfType('users.updated_at', 'string', $schemaAggregator);
    }
}
