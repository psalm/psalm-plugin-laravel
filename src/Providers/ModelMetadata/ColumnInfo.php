<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers\ModelMetadata;

/**
 * Snapshot of a single database column inferred from migration schema.
 *
 * {@see TableSchema} indexes entries by the original-case column name
 * (matching Eloquent's case-sensitive runtime attribute lookup); `$name`
 * stays available for diagnostic messages that need to quote the exact key.
 *
 * @psalm-immutable
 * @psalm-api
 * @internal
 */
final readonly class ColumnInfo
{
    /**
     * @param non-empty-string $name     Column name in its original case.
     * @param non-empty-string $sqlType  Underlying SQL type identifier (e.g. "int", "string", "enum").
     * @param list<string>     $options  Allowed literal values for ENUM columns, empty otherwise.
     *                                   Preserved from the migration parser (e.g. Blueprint::enum()'s
     *                                   second argument) so consumers can emit literal-string unions.
     */
    public function __construct(
        public string $name,
        public string $sqlType,
        public bool $nullable,
        public bool $hasDefault,
        public bool $unsigned = false,
        public array $options = [],
    ) {}
}
