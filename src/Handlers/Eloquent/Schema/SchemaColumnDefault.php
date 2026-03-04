<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Schema;

/**
 * Represents a column's default value from a migration.
 *
 * Three states:
 * - No default: SchemaColumn::$default is null
 * - Resolved default: SchemaColumnDefault::resolved($value) — value is known
 * - Unresolvable default: SchemaColumnDefault::unresolvable() — default exists but the value
 *   couldn't be statically resolved (e.g. `new Expression('NOW()')`, variables, function calls)
 *
 * @psalm-suppress PossiblyUnusedProperty will be used for model attribute type inference
 * @psalm-immutable
 */
final class SchemaColumnDefault
{
    private function __construct(
        public readonly string|int|float|bool|null $value,
        public readonly bool $resolvable,
    ) {
    }

    public static function resolved(string|int|float|bool|null $value): self
    {
        return new self($value, true);
    }

    public static function unresolvable(): self
    {
        return new self(null, false);
    }
}
