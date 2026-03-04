<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;

#[CoversClass(SchemaAggregator::class)]
final class DefaultUserTableTest extends AbstractSchemaAggregatorTestCase
{
    #[Test]
    public function it_detects_all_columns_from_anonymous_class_migration(): void
    {
        $schemaAggregator = $this->instantiateSchemaAggregator(__DIR__ . '/migrations/default_users_table_anon');

        $this->assertAllDefaultUsersTableColumnsDetectedProperly($schemaAggregator);
    }

    #[Test]
    public function it_detects_all_columns_from_named_class_migration(): void
    {
        $schemaAggregator = $this->instantiateSchemaAggregator(__DIR__ . '/migrations/default_users_table_named');

        $this->assertAllDefaultUsersTableColumnsDetectedProperly($schemaAggregator);
    }

    #[Test]
    public function it_detects_all_columns_from_migration_that_uses_root_namespace_facades(): void
    {
        $schemaAggregator = $this->instantiateSchemaAggregator(__DIR__ . '/migrations/default_users_table_root_ns_facade');

        $this->assertAllDefaultUsersTableColumnsDetectedProperly($schemaAggregator);
    }

    private function assertAllDefaultUsersTableColumnsDetectedProperly(SchemaAggregator $schemaAggregator): void
    {
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
