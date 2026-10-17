<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Relations\Pivot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\PivotColumnTypeResolver;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaStateProvider;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaTable;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

#[CoversClass(PivotColumnTypeResolver::class)]
final class PivotColumnTypeResolverTest extends TestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        SchemaStateProvider::reset();
    }

    #[Test]
    public function known_nullable_schema_column_maps_through_column_type_mapper(): void
    {
        $schema = new SchemaAggregator();
        $table = new SchemaTable();
        $table->setColumn(new SchemaColumn('role_id', SchemaColumn::TYPE_INT, nullable: true, unsigned: true));

        $schema->setTable('owner_related', $table);
        SchemaStateProvider::setSchema($schema);

        $type = PivotColumnTypeResolver::refinePivot(
            new Union([new TNamedObject(Pivot::class)]),
            'owner_related',
            ['role_id'],
        );

        $this->assertSame(Pivot::class . '&object{role_id:int<0, max>|null}', $type->getId());
    }

    #[Test]
    public function absent_table_or_column_keeps_selected_property_mixed(): void
    {
        $schema = new SchemaAggregator();
        SchemaStateProvider::setSchema($schema);

        $type = PivotColumnTypeResolver::refinePivot(
            new Union([new TNamedObject(Pivot::class)]),
            'missing_table',
            ['role'],
        );

        $this->assertSame(Pivot::class . '&object{role:mixed}', $type->getId());
    }

}
