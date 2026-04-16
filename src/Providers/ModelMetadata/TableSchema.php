<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers\ModelMetadata;

/**
 * Immutable view of a model's database table columns.
 *
 * Keys are lowercased column names (the §5.5 naming convention);
 * {@see ColumnInfo::$name} preserves the original case.
 *
 * @psalm-immutable
 * @psalm-api
 * @internal
 */
final readonly class TableSchema
{
    /**
     * @param array<non-empty-lowercase-string, ColumnInfo> $columns
     */
    public function __construct(private array $columns) {}

    public function has(string $name): bool
    {
        if ($name === '') {
            return false;
        }

        return isset($this->columns[\strtolower($name)]);
    }

    public function column(string $name): ?ColumnInfo
    {
        if ($name === '') {
            return null;
        }

        return $this->columns[\strtolower($name)] ?? null;
    }

    /**
     * Fast-path existence check for callers that already hold a lowercased key.
     *
     * @param non-empty-lowercase-string $key
     */
    public function hasLowerKey(string $key): bool
    {
        return isset($this->columns[$key]);
    }

    /**
     * Fast-path column lookup for callers that already hold a lowercased key
     * (hot path in `ModelPropertyHandler::getPropertyType`, which lowercases
     * once and reuses the result for both the schema and cast lookups).
     *
     * @param non-empty-lowercase-string $key
     */
    public function columnByLowerKey(string $key): ?ColumnInfo
    {
        return $this->columns[$key] ?? null;
    }

    /**
     * Iterate all columns keyed by their lowercased name.
     *
     * @return array<non-empty-lowercase-string, ColumnInfo>
     */
    public function all(): array
    {
        return $this->columns;
    }
}
