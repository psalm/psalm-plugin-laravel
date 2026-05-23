<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Util;

use Psalm\Type;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Translates a runtime config value into a Psalm Union.
 *
 * Scalars are generalized (not literal) because booted Laravel collapses
 * env-driven values to a single observation at analysis time. Narrowing
 * `config('app.debug')` to `false` would trigger `TypeDoesNotContainType` on
 * every `if (config('app.debug'))` callsite that overrides via env.
 *
 * Arrays preserve shape up to {@see MAX_DEPTH}/{@see MAX_KEYS_PER_LEVEL}; a
 * per-tree {@see MAX_TOTAL_PROPERTIES} budget guards against a branching
 * shallow array building a megabyte-scale type string before the depth cap
 * fires. Beyond any cap, degrade to `array<array-key, mixed>`.
 *
 * Resources and unknown values fall back to `mixed`.
 *
 * @internal
 */
final class ConfigValueReflector
{
    public const MAX_DEPTH = 5;

    public const MAX_KEYS_PER_LEVEL = 64;

    /**
     * Total keyed-array properties allowed across one top-level reflection.
     * 512 covers Filament-scale configs (~hundreds of keyed leaves) without
     * letting a branching shape produce multi-MB type identifiers.
     */
    public const MAX_TOTAL_PROPERTIES = 512;

    public static function reflect(mixed $value): Union
    {
        $remainingBudget = self::MAX_TOTAL_PROPERTIES;

        return self::reflectInternal($value, 0, $remainingBudget);
    }

    /**
     * @param int<0, max> $depth
     */
    private static function reflectInternal(mixed $value, int $depth, int &$remainingBudget): Union
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
            return self::reflectArray($value, $depth, $remainingBudget);
        }

        if (\is_object($value)) {
            // Stored closures: `Repository::get` does NOT auto-invoke them
            // (`value()` only runs on the $default branch inside `Arr::get`).
            // Return Closure as a plain named object so callers can $closure().
            return new Union([new TNamedObject($value::class)]);
        }

        // Resources and any other unsupported value kinds.
        return Type::getMixed();
    }

    /**
     * @param array<array-key, mixed> $value
     * @param int<0, max> $depth
     */
    private static function reflectArray(array $value, int $depth, int &$remainingBudget): Union
    {
        if ($value === []) {
            return Type::getEmptyArray();
        }

        $count = \count($value);

        if ($depth >= self::MAX_DEPTH
            || $count > self::MAX_KEYS_PER_LEVEL
            || $count > $remainingBudget
        ) {
            return Type::getArray();
        }

        $remainingBudget -= $count;
        $is_list = \array_is_list($value);
        $nextDepth = $depth + 1;

        // array_map (not foreach) keeps the per-element value off a local
        // mixed variable, which would otherwise count against type coverage.
        // Long-form closure (not `fn() =>`) is required to capture
        // $remainingBudget by reference — arrow functions only do by-value.
        $properties = \array_map(
            static function (mixed $sub_value) use ($nextDepth, &$remainingBudget): Union {
                return self::reflectInternal($sub_value, $nextDepth, $remainingBudget);
            },
            $value,
        );

        // TKeyedArray ctor requires non-empty-array; the empty branch is handled
        // above. The post-check narrows array_map's loose `array<array-key, Union>`
        // return type back to non-empty for Psalm.
        if ($properties === []) {
            return Type::getEmptyArray();
        }

        return new Union([new TKeyedArray($properties, null, null, $is_list)]);
    }
}
