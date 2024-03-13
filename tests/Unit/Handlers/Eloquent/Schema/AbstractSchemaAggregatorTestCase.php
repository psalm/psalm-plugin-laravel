<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Psalm\Internal\Provider\StatementsProvider;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaTable;

use function glob;
use function file_get_contents;
use function explode;
use function is_dir;
use function is_file;
use function realpath;

use const DIRECTORY_SEPARATOR;
use const PHP_VERSION_ID;

/** @covers \Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator */
abstract class AbstractSchemaAggregatorTestCase extends TestCase
{
    final protected function instantiateSchemaAggregator(string $filepath): SchemaAggregator
    {
        if (is_file($filepath)) {
            $migrationFiles = [$filepath];
        } elseif (is_dir($filepath)) {
            $migrationsDirectory = realpath($filepath) . DIRECTORY_SEPARATOR;
            $migrationFiles = glob($migrationsDirectory . '*.php');

            if ($migrationFiles === []) {
                $this->fail("Migrations not found in “{$migrationsDirectory}” directory.");
            }
        } else {
            $this->fail("“{$filepath}” is not a file or directory.");
        }

        $schemaAggregator = new SchemaAggregator();
        $hasErrors = false;
        foreach ($migrationFiles as $migrationFile) {
            $fileContents = file_get_contents($migrationFile);
            if ($fileContents === false) {
                $this->fail("Could not read $migrationFile file. Please make sure it exists and readable.");
            }

            $statements = StatementsProvider::parseStatements($fileContents, PHP_VERSION_ID, $hasErrors);

            $schemaAggregator->addStatements($statements);
        }

        return $schemaAggregator;
    }

    protected function assertColumnHasType(string $type, SchemaColumn $column): void
    {
        Assert::assertSame($type, $column->type);
    }

    protected function assertColumnNullable(SchemaColumn $column): void
    {
        Assert::assertTrue($column->nullable);
    }

    protected function assertColumnNotNullable(SchemaColumn $column): void
    {
        Assert::assertFalse($column->nullable);
    }

    protected function assertTableHasColumn(string $column, SchemaTable $table): void
    {
        Assert::assertArrayHasKey($column, $table->columns);
    }

    protected function assertTableHasNullableColumnOfType(string $column, string $type, SchemaTable $table): void
    {
        self::assertTableHasColumn($column, $table);

        $column = $table->columns[$column];
        self::assertInstanceOf(SchemaColumn::class, $column);

        self::assertColumnHasType($type, $column);
        self::assertColumnNullable($column);
    }

    protected function assertTableHasNotNullableColumnOfType(string $column, string $type, SchemaTable $table): void
    {
        self::assertTableHasColumn($column, $table);

        $column = $table->columns[$column];
        self::assertInstanceOf(SchemaColumn::class, $column);

        self::assertColumnHasType($type, $column);
        self::assertColumnNotNullable($column);
    }

    protected function assertSchemaHasTableAndColumn(string $tableWithColumn, string $type, SchemaAggregator $schemaAggregator): void
    {
        [$tableName, $columnName] = self::parseTableWithColumn($tableWithColumn);
        $table = $schemaAggregator->tables[$tableName];
        self::assertInstanceOf(SchemaTable::class, $table);

        self::assertTableHasColumn($columnName, $table);
        self::assertTableHasColumn($type, $columnName);
    }

    protected function assertSchemaHasTableAndColumnOfType(string $tableWithColumn, string $type, SchemaAggregator $schemaAggregator): void
    {
        [$table, $column] = self::parseTableWithColumn($tableWithColumn);
        self::assertColumnHasType($type, $schemaAggregator->tables[$table]->columns[$column]);
    }

    protected function assertSchemaHasTableAndNullableColumnOfType(string $tableWithColumn, string $type, SchemaAggregator $schemaAggregator): void
    {
        self::assertSchemaHasTableAndColumnOfType($tableWithColumn, $type, $schemaAggregator);

        [$tableName, $columnName] = self::parseTableWithColumn($tableWithColumn);

        self::assertTrue($schemaAggregator->tables[$tableName]->columns[$columnName]->nullable, "Column $tableWithColumn is not nullable");
    }

    protected function assertSchemaHasTableAndNotNullableColumnOfType(string $tableWithColumn, string $type, SchemaAggregator $schemaAggregator): void
    {
        self::assertSchemaHasTableAndColumnOfType($tableWithColumn, $type, $schemaAggregator);

        [$tableName, $columnName] = self::parseTableWithColumn($tableWithColumn);

        self::assertFalse($schemaAggregator->tables[$tableName]->columns[$columnName]->nullable, "Column $tableWithColumn is nullable");
    }

    /**
     * @return array{0: non-empty-string, 1: non-empty-string}
     */
    private function parseTableWithColumn(string $tableWithColumn): array
    {
        [$tableName, $columnName] = explode('.', $tableWithColumn);
        // @todo validate these values

        return [$tableName, $columnName];
    }
}
