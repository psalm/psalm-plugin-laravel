<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Schema;

use Psalm\LaravelPlugin\Providers\ModelMetadata\ColumnInfo;
use Psalm\Type;
use Psalm\Type\Union;

/**
 * Pure SQL-type → Psalm-type mapping for a migration-inferred {@see ColumnInfo}.
 *
 * Lives in the Schema namespace (not on a Handler) so BOTH the read-path handler
 * ({@see \Psalm\LaravelPlugin\Handlers\Eloquent\ModelPropertyHandler}) and the metadata
 * builder ({@see \Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadataRegistryBuilder})
 * depend on it directly: the dependency runs Handler→Schema / Provider→Schema, never
 * Provider→Handler. Previously this mapping lived on {@see ModelPropertyHandler}, which the
 * builder had to reach back into for the {@see \Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes}
 * passthrough base type — an undesigned cycle the relocation dissolves.
 *
 * @internal
 */
final class ColumnTypeMapper
{
    /**
     * Non-nullable base mapping for a schema column. The metadata builder reads this to obtain a
     * column's intrinsic Psalm type for {@see \Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes}
     * casts (whose read path is a passthrough of the raw DB type), letting the cast resolver decide
     * how to apply nullability on the final union. The handler's read path wraps it with nullability
     * via {@see \Psalm\LaravelPlugin\Handlers\Eloquent\ModelPropertyHandler}.
     */
    public static function mapBaseType(ColumnInfo $column): Union
    {
        return match ($column->sqlType) {
            SchemaColumn::TYPE_INT => $column->unsigned
                ? new Union([new Type\Atomic\TIntRange(0, null)])
                : Type::getInt(),
            SchemaColumn::TYPE_STRING => Type::getString(),
            SchemaColumn::TYPE_FLOAT => Type::getFloat(),
            SchemaColumn::TYPE_BOOL => Type::getBool(),
            // MySQL SET is comma-separated at runtime (e.g. 'draft,published'), so the
            // literal-union here is an over-narrowing approximation — strictly better than
            // `mixed` for the common `in_array($model->status, [...])` check. Matches Larastan.
            SchemaColumn::TYPE_ENUM, SchemaColumn::TYPE_SET => self::mapLiteralUnionFromOptions($column),
            SchemaColumn::TYPE_ARRAY => new Union([Type\Atomic\TKeyedArray::make(
                [Type::getFloat()],
                fallback_params: [Type::getInt(), Type::getFloat()],
                is_list: true,
            )]),
            default => Type::getMixed(),
        };
    }

    /**
     * Build a literal-string union from a column's options list. Shared by ENUM and SET
     * because both store their option set the same way in {@see ColumnInfo::$options}
     * and benefit from the same narrowing (with the SET caveat documented at the caller).
     */
    private static function mapLiteralUnionFromOptions(ColumnInfo $column): Union
    {
        if ($column->options === []) {
            return Type::getString();
        }

        try {
            $literals = [];
            foreach ($column->options as $option) {
                $literals[] = Type\Atomic\TLiteralString::make($option);
            }

            return new Union($literals);
        } catch (\UnexpectedValueException|\InvalidArgumentException) {
            // TLiteralString::make() throws InvalidArgumentException when an option
            // exceeds Config::max_string_length, and UnexpectedValueException when
            // called outside an initialized Psalm Config (e.g. unit tests). Mirrors
            // {@see \Psalm\LaravelPlugin\Handlers\Validation\ValidationRuleAnalyzer::inRuleToLiteralUnion}.
            return Type::getString();
        }
    }
}
