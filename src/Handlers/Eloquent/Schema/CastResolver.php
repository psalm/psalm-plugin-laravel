<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Schema;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Resolves a Laravel cast string (e.g., 'integer', 'datetime', 'array') to a Psalm type.
 *
 * @internal
 */
final class CastResolver
{
    /**
     * Resolve a cast string to a Psalm type union.
     *
     * @param string $cast The cast string from the model's $casts property or casts() method
     * @param bool $nullable Whether the underlying column is nullable
     */
    public static function resolve(string $cast, bool $nullable): Union
    {
        $baseCast = \strtolower($cast);

        // Handle encrypted casts first: encrypted:X → recursively resolve X
        if (\str_starts_with($baseCast, 'encrypted:')) {
            $innerCast = \substr($cast, 10); // preserve original case for class names
            return self::resolve($innerCast, $nullable);
        }

        // Handle enum casts: enum:App\Enums\Status → the enum class
        if (\str_starts_with($baseCast, 'enum:')) {
            $enumClass = \substr($cast, 5); // preserve original case
            if (\enum_exists($enumClass)) {
                return self::makeNullable(new Union([new TNamedObject($enumClass)]), $nullable);
            }

            return self::makeNullable(Type::getMixed(), $nullable);
        }

        // Strip parameters after colon (e.g., 'decimal:2' → 'decimal')
        if (\str_contains($baseCast, ':')) {
            $baseCast = \substr($baseCast, 0, (int) \strpos($baseCast, ':'));
        }

        $type = self::resolveBaseCast($baseCast, $nullable);

        if ($type instanceof \Psalm\Type\Union) {
            return $type;
        }

        // Check for backed enum
        if (\enum_exists($cast)) {
            return self::makeNullable(new Union([new TNamedObject($cast)]), $nullable);
        }

        // Check for CastsAttributes implementation
        if (\class_exists($cast) || \interface_exists($cast)) {
            if (\is_a($cast, CastsAttributes::class, true)) {
                return self::makeNullable(Type::getMixed(), $nullable);
            }

            if (\is_a($cast, Attribute::class, true)) {
                return self::makeNullable(Type::getMixed(), $nullable);
            }
        }

        // Unknown cast → mixed
        return self::makeNullable(Type::getMixed(), $nullable);
    }

    private static function resolveBaseCast(string $baseCast, bool $nullable): ?Union
    {
        return match ($baseCast) {
            'int', 'integer' => self::makeNullable(Type::getInt(), $nullable),
            'real', 'float', 'double' => self::makeNullable(Type::getFloat(), $nullable),
            'decimal' => self::makeNullable(Type::getString(), $nullable),
            'string' => self::makeNullable(Type::getString(), $nullable),
            'bool', 'boolean' => self::makeNullable(Type::getBool(), $nullable),
            'object' => self::makeNullable(new Union([new Type\Atomic\TObjectWithProperties([])]), $nullable),
            'array', 'json' => self::makeNullable(Type::getArray(), $nullable),
            'collection' => self::makeNullable(
                new Union([new TNamedObject(\Illuminate\Support\Collection::class)]),
                $nullable,
            ),
            'date', 'datetime', 'custom_datetime' => self::makeNullable(
                new Union([new TNamedObject(\Illuminate\Support\Carbon::class)]),
                $nullable,
            ),
            'immutable_date', 'immutable_datetime', 'immutable_custom_datetime' => self::makeNullable(
                new Union([new TNamedObject(\Carbon\CarbonImmutable::class)]),
                $nullable,
            ),
            'timestamp' => self::makeNullable(Type::getInt(), $nullable),
            'encrypted' => self::makeNullable(Type::getMixed(), $nullable),
            'hashed' => self::makeNullable(Type::getString(), $nullable),
            default => null,
        };
    }

    /** @psalm-external-mutation-free */
    private static function makeNullable(Union $type, bool $nullable): Union
    {
        if (!$nullable) {
            return $type;
        }

        return Type::combineUnionTypes($type, Type::getNull());
    }
}
