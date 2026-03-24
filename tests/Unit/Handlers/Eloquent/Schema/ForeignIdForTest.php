<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;

#[CoversClass(SchemaAggregator::class)]
final class ForeignIdForTest extends AbstractSchemaAggregatorTestCase
{
    #[Test]
    public function foreign_id_for_with_custom_column_name(): void
    {
        $schemaAggregator = $this->instantiateSchemaAggregator(
            __DIR__ . '/migrations/foreign_id_for',
        );

        $this->assertArrayHasKey('posts', $schemaAggregator->tables);
        $table = $schemaAggregator->tables['posts'];

        // foreignIdFor(User::class, 'author_id') should use 'author_id'
        self::assertTableHasColumn('author_id', $table);
        self::assertColumnHasType('int', $table->columns['author_id']);
        $this->assertTrue($table->columns['author_id']->unsigned, 'foreignIdFor should produce unsigned column');

        // The old bug would have created a column named 'id' instead
        // With the fix, the only 'id' column is from $table->id()
        $this->assertSame('id', $table->columns['id']->name);
    }

    #[Test]
    public function foreign_id_for_with_namespaced_string_is_skipped(): void
    {
        $schemaAggregator = $this->instantiateSchemaAggregator(
            __DIR__ . '/migrations/foreign_id_for_namespaced_string',
        );

        $this->assertArrayHasKey('reviews', $schemaAggregator->tables);
        $table = $schemaAggregator->tables['reviews'];

        // foreignIdFor('App\Models\User') passes the FQCN as a string literal —
        // the plugin cannot resolve this statically and must skip it rather than
        // registering a bogus column named 'App\Models\User'
        $this->assertArrayNotHasKey('App\Models\User', $table->columns);

        // The other columns should still be present
        self::assertTableHasColumn('id', $table);
        self::assertTableHasColumn('body', $table);
    }
}
