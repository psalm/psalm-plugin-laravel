<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Util;

use Psalm\Type;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Translates a runtime config value (resolved from the booted Laravel app) into a
 * Psalm Union type.
 *
 * Scalars are intentionally generalized to their non-literal form. Booted Laravel
 * resolves env-driven values to a single concrete observation at analysis time;
 * narrowing `config('app.debug')` to `false` (its observed default) would surface
 * spurious `TypeDoesNotContainType` issues on every `if (config('app.debug'))`.
 * Returning `bool`/`string`/`int` keeps the call site flexible to runtime overrides.
 *
 * Arrays preserve shape up to {@see self::MAX_DEPTH} levels deep and
 * {@see self::MAX_KEYS_PER_LEVEL} keys per level. Beyond that, the reflector
 * degrades to `array<array-key, mixed>` rather than emitting megabyte-scale type
 * strings.
 *
 * Closures, resources, and unknown values fall back to `mixed`.
 *
 * @internal
 */
final class ConfigValueReflector
{
    public const MAX_DEPTH = 5;

    public const MAX_KEYS_PER_LEVEL = 64;

    public static function reflect(mixed $value, int $depth = 0): Union
    {
        if ($value === null) {
            return Type::getNull();
        }

        if (\is_bool($value)) {
            return Type::getBool();
        }

        if (\is_int($value)) {
            return Type::getInt();
        }

        if (\is_float($value)) {
            return Type::getFloat();
        }

        if (\is_string($value)) {
            return Type::getString();
        }

        if (\is_array($value)) {
            return self::reflectArray($value, $depth);
        }

        if ($value instanceof \Closure) {
            // value() (used by Repository::get) invokes closures lazily — static
            // analysis cannot follow the body without an explicit return type.
            return Type::getMixed();
        }

        if (\is_object($value)) {
            return new Union([new TNamedObject($value::class)]);
        }

        // Resources and any other unsupported value kinds.
        return Type::getMixed();
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function reflectArray(array $value, int $depth): Union
    {
        if ($value === []) {
            return Type::getEmptyArray();
        }

        if ($depth >= self::MAX_DEPTH || \count($value) > self::MAX_KEYS_PER_LEVEL) {
            return Type::getArray();
        }

        $is_list = \array_is_list($value);

        $properties = [];
        /** @psalm-var mixed $sub_value */
        foreach ($value as $key => $sub_value) {
            $properties[$key] = self::reflect($sub_value, $depth + 1);
        }

        // TKeyedArray::make requires non-empty-array; the empty branch is handled
        // above. Make returns TKeyedArray|TArray (TArray only when properties
        // collapse to never, which cannot happen here).
        return new Union([TKeyedArray::make($properties, null, null, is_list: $is_list)]);
    }
}
