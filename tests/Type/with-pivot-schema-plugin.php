<?php

declare(strict_types=1);

use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaStateProvider;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaTable;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;

/**
 * Isolated type-test bootstrap: injects just the pivot tables needed by the PHPT.
 */
final class WithPivotSchemaTestPlugin implements AfterCodebasePopulatedInterface
{
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $schema = new SchemaAggregator();
        $schema->setTable('pivot_schema_owner_user', self::table([
            new SchemaColumn('role', SchemaColumn::TYPE_STRING),
            new SchemaColumn('active', SchemaColumn::TYPE_BOOL),
            new SchemaColumn('score', SchemaColumn::TYPE_INT),
            new SchemaColumn('note', SchemaColumn::TYPE_STRING, nullable: true),
        ]));
        $schema->setTable('pivot_schema_named', self::table([
            new SchemaColumn('active', SchemaColumn::TYPE_BOOL),
            new SchemaColumn('score', SchemaColumn::TYPE_INT),
            new SchemaColumn('role', SchemaColumn::TYPE_STRING),
        ]));
        $schema->setTable('pivot_schema_morph', self::table([
            new SchemaColumn('role', SchemaColumn::TYPE_STRING),
        ]));
        SchemaStateProvider::setSchema($schema);
    }

    /** @param list<SchemaColumn> $columns */
    private static function table(array $columns): SchemaTable
    {
        $table = new SchemaTable();
        foreach ($columns as $column) {
            $table->setColumn($column);
        }

        return $table;
    }
}
