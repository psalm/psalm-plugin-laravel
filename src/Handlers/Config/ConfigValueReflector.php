<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Config;

use PhpParser\Node\Expr;
use Psalm\Type;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TBool;
use Psalm\Type\Atomic\TFalse;
use Psalm\Type\Atomic\TFloat;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TScalar;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Atomic\TTrue;
use Psalm\Type\Union;

/**
 * Translates a runtime config value into a Psalm Union.
 *
 * A value whose config-file expression reads the environment is typed from that expression
 * ({@see EnvExpressionTyper}), so the env of the machine running Psalm never leaks in. Runtime
 * keys still drive the walk: providers add, drop and override keys after the files load.
 *
 * Other scalars are generalized (not literal) because the runtime value is one observation.
 * A runtime `null` reflects as `null` only when the config file literally says `null`; with
 * no source behind it, it is as likely "not configured on this machine" and becomes `mixed`.
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

    public static function reflect(#[\SensitiveParameter] mixed $value, ?ConfigSource $source = null): Union
    {
        $remainingBudget = self::MAX_TOTAL_PROPERTIES;

        return self::reflectInternal($value, $source, 0, $remainingBudget);
    }

    /** @param int<0, max> $depth */
    private static function reflectInternal(
        #[\SensitiveParameter]
        mixed $value,
        ?ConfigSource $source,
        int $depth,
        int &$remainingBudget,
    ): Union {
        $expression = $source?->expression();

        // A literal array is walked below instead, so its env-free items keep their runtime shape.
        if ($expression instanceof Expr && ConfigSourceIndex::literalItems($expression) === null) {
            $readsEnv = EnvExpressionTyper::readsEnv($expression);

            // A value the expression cannot produce was set by a provider after the file loaded.
            if ($readsEnv === true && self::admits(EnvExpressionTyper::producible($expression), $value)) {
                return EnvExpressionTyper::type($expression);
            }

            if ($readsEnv !== false) {
                $source = null;
                $expression = null;
            }
        }

        if ($value === null) {
            return $expression instanceof Expr\ConstFetch && $expression->name->toLowerString() === 'null'
                ? Type::getNull()
                : Type::getMixed();
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
            return self::reflectArray($value, $source, $depth, $remainingBudget);
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
     * Whether $type has an atomic for the kind of $value. Array contents are not compared.
     *
     * @psalm-pure
     */
    private static function admits(Union $type, #[\SensitiveParameter] mixed $value): bool
    {
        foreach ($type->getAtomicTypes() as $atomic) {
            // Psalm folds bool|float|int|string into scalar.
            if (($atomic instanceof TScalar && \is_scalar($value)) || match (true) {
                $value === null => $atomic instanceof TNull,
                \is_bool($value) => \in_array($atomic::class, [TBool::class, $value ? TTrue::class : TFalse::class], true),
                \is_int($value) => $atomic instanceof TInt,
                \is_float($value) => $atomic instanceof TFloat,
                \is_string($value) => $atomic instanceof TString,
                \is_array($value) => $atomic instanceof TArray || $atomic instanceof TKeyedArray,
                \is_object($value) => $atomic instanceof TNamedObject && $atomic->value === $value::class,
                default => false,
            }) {
                return true;
            }
        }

        return $type->hasMixed();
    }

    /**
     * @param array<array-key, mixed> $value
     * @param int<0, max> $depth
     */
    private static function reflectArray(
        #[\SensitiveParameter]
        array $value,
        ?ConfigSource $source,
        int $depth,
        int &$remainingBudget,
    ): Union {
        if ($value === []) {
            return Type::getEmptyArray();
        }

        $count = \count($value);

        if ($depth >= self::MAX_DEPTH || $count > self::MAX_KEYS_PER_LEVEL || $count > $remainingBudget) {
            return Type::getArray();
        }

        $remainingBudget -= $count;
        $is_list = \array_is_list($value);
        $nextDepth = $depth + 1;

        // The element goes straight into the call: a foreach value variable would be a mixed
        // assignment, which counts against type coverage.
        $properties = [];
        foreach (\array_keys($value) as $key) {
            $properties[$key] = self::reflectInternal($value[$key], $source?->child($key), $nextDepth, $remainingBudget);
        }

        // TKeyedArray::make requires non-empty-array; the empty branch is handled
        // above. Make returns TKeyedArray|TArray (TArray only when properties
        // collapse to never, which cannot happen here).
        return new Union([TKeyedArray::make($properties, null, null, is_list: $is_list)]);
    }
}
