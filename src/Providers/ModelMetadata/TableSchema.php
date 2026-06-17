<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers\ModelMetadata;

/**
 * Immutable view of a model's database table columns.
 *
 * Keys preserve the original case produced by the migration parser, matching
 * Eloquent's case-sensitive attribute semantics at runtime. Consumers that
 * want case-insensitive lookup must normalize their query key themselves.
 *
 * @psalm-immutable
 * @psalm-api
 * @internal
 */
final readonly class TableSchema
{
    /**
     * @param array<non-empty-string, ColumnInfo> $columns
     */
    public function __construct(private array $columns) {}

    public function has(string $name): bool
    {
        if ($name === '') {
            return false;
        }

        return isset($this->columns[$name]);
    }

    public function column(string $name): ?ColumnInfo
    {
        if ($name === '') {
            return null;
        }

        return $this->columns[$name] ?? null;
    }

    /**
     * Iterate all columns keyed by their original-case name.
     *
     * @return array<non-empty-string, ColumnInfo>
     */
    public function all(): array
    {
        return $this->columns;
    }
}
