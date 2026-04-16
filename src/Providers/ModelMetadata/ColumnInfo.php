<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers\ModelMetadata;

/**
 * Snapshot of a single database column inferred from migration schema.
 *
 * Keeps the original-case `$name` for diagnostic messages; the parent
 * {@see TableSchema} indexes by the lowercased key.
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
