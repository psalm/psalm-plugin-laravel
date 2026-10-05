<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Schema;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;
use Illuminate\Database\Eloquent\Casts\ArrayObject as EloquentArrayObject;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Casts\AsStringable;
use Illuminate\Support\Collection as IlluminateCollection;
use Illuminate\Support\Stringable as IlluminateStringable;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Internal\ClassLineage;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type;
use Psalm\Type\Atomic\TClassString;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Resolves a Laravel cast string (e.g. 'integer', 'datetime', or a user-defined
 * Castable / CastsAttributes / CastsInboundAttributes class) to a Psalm type.
 *
 * Mirrors the resolution priority of Larastan's `ModelCastHelper::getReadableType`
 * (see .cache/larastan/src/Properties/ModelCastHelper.php) so users of either tool
 * see the same inferred types for the same cast.
 *
 * Class lookups on a cast target (enum? Castable? CastsAttributes? ...) are two-tier (#1652):
 * 1. Psalm's storage, via {@see ClassLineage} (leading backslash and `class_alias()` names resolved).
 *    It never loads the target, so a file that raises a deprecation on load cannot drop the casts.
 * 2. Only when Psalm never scanned the target (a string cast such as `'Vendor\Status'` is no
 *    reference, so nothing queues it for scanning): the runtime, as before. The target is autoloaded
 *    at most once per process ({@see autoloadOnce()}); every check then reads only declared classes.
 *    A target that fails to load is an unknown cast (mixed).
 *
 * @internal
 */
final class CastResolver
{
    /**
     * Unscanned cast targets whose autoload was already attempted, keyed by lowercase name. A file that
     * fails mid-load (Psalm's error handler throws on a deprecation it raises) keeps what it declared
     * before the failure, so including it again dies with an uncatchable "Cannot redeclare" fatal.
     *
     * Deliberately not reset per invocation (code-patterns.md: process-invariant caches need no reset):
     * it mirrors PHP's process state, which a partial load cannot undo, so a re-invocation in the same
     * process must not retry the include either.
     *
     * @var array<lowercase-string, true>
     */
    private static array $autoloadAttempted = [];

    /**
     * Resolve a cast string to a Psalm readable type.
     *
     * @param string $cast Raw cast value from `$casts` / `casts()` (may include parameters: `Money:USD`).
     * @param bool $nullable Whether the underlying column is nullable.
     * @param Union|null $originalType The column's intrinsic mapped Psalm type without nullability
     *     applied (typically from {@see ColumnTypeMapper::mapBaseType}).
     *     Used as the read type for {@see CastsInboundAttributes} (write-only contracts whose
     *     read path is a passthrough of the column's mapped type). Pass `null` when the column
     *     type is unknown; CastsInboundAttributes will then fall back to `mixed`.
     */
    public static function resolve(
        Codebase $codebase,
        string $cast,
        bool $nullable,
        ?Union $originalType = null,
    ): Union {
        $baseCast = \strtolower($cast);

        // `encrypted:X` — Laravel only supports a fixed inner-cast set here. Anything outside
        // {array, json, collection, object} is NOT recognised by HasAttributes::isEncryptedCastable
        // and would be treated as a (non-existent) custom class cast; recursing for arbitrary
        // suffixes would over-promise types Laravel never produces.
        // See vendor/laravel/framework/src/Illuminate/Database/Eloquent/Concerns/HasAttributes.php::isEncryptedCastable.
        if (\str_starts_with($baseCast, 'encrypted:')) {
            $inner = \substr($baseCast, 10);
            if (\in_array($inner, ['array', 'json', 'collection', 'object'], true)) {
                return self::resolve($codebase, \substr($cast, 10), $nullable, $originalType);
            }

            return self::makeNullable(Type::getMixed(), $nullable);
        }

        // `enum:App\Enums\Status` — typed enum casts (preserve original case for the class name).
        if (\str_starts_with($baseCast, 'enum:')) {
            $enumClass = ClassLineage::canonicalName($codebase, \substr($cast, 5));
            if (self::isEnum($codebase, $enumClass)) {
                return self::makeNullable(new Union([new TNamedObject($enumClass)]), $nullable);
            }

            return self::makeNullable(Type::getMixed(), $nullable);
        }

        // Strip parameter suffix from BOTH the lowercased base AND the case-preserving class name
        // so the class lookups below work for class-based casts with arguments (`Money:USD`). The
        // class name is canonical, so a type it resolves to names the class, not an alias of it.
        $baseCast = self::stripParameters($baseCast);
        $castClass = ClassLineage::canonicalName($codebase, self::stripParameters($cast));

        $type = self::resolveBaseCast($baseCast, $nullable, $originalType);
        if ($type instanceof Union) {
            return $type;
        }

        // Framework-shipped Castable casts. Hardcoded for parity with Larastan and to insulate
        // ourselves from docblock drift across Laravel versions — the dynamic Castable-chase below
        // would technically work, but locking these to known-good types matches the explicit
        // success criterion of the issue (#930).
        $type = self::resolveFrameworkCast($castClass);
        if ($type instanceof Union) {
            return $type;
        }

        // Backed enum class reference (`MyEnum::class` in `$casts`).
        if (self::isEnum($codebase, $castClass)) {
            return self::makeNullable(new Union([new TNamedObject($castClass)]), $nullable);
        }

        // Order matters: a class may implement both Castable and CastsAttributes (e.g. a value
        // object that is its own caster). Try Castable's `castUsing()` chase first; only fall
        // back to direct CastsAttributes reflection if the chase yields nothing.
        if (self::castTargetIsA($codebase, $castClass, Castable::class)) {
            $type = self::resolveCastable($codebase, $castClass, $nullable, $originalType);
            if ($type instanceof Union) {
                return $type;
            }
        }

        if (self::castTargetIsA($codebase, $castClass, CastsAttributes::class)) {
            $type = self::resolveCastsAttributesGet($codebase, $castClass, $nullable);
            if ($type instanceof Union) {
                return $type;
            }
        }

        if (self::castTargetIsA($codebase, $castClass, CastsInboundAttributes::class)) {
            // Reading a write-only cast is a passthrough of the raw column value; Larastan
            // returns `$originalType` here. We do the same when the caller supplies one.
            if ($originalType instanceof Union) {
                return self::makeNullable($originalType, $nullable);
            }

            return self::makeNullable(Type::getMixed(), $nullable);
        }

        // Unknown cast → mixed (current behavior preserved for non-resolvable user casts).
        return self::makeNullable(Type::getMixed(), $nullable);
    }

    /** Two-tier, see the class docblock. */
    private static function isEnum(Codebase $codebase, string $class): bool
    {
        $storage = ClassLineage::storage($codebase, $class);
        if ($storage instanceof ClassLikeStorage) {
            return $storage->is_enum;
        }

        self::autoloadOnce($class);

        return \enum_exists($class, false);
    }

    /**
     * Two-tier, see the class docblock.
     *
     * @param class-string $ancestor
     */
    private static function castTargetIsA(Codebase $codebase, string $class, string $ancestor): bool
    {
        if (ClassLineage::storage($codebase, $class) instanceof ClassLikeStorage) {
            return ClassLineage::isA($codebase, $class, $ancestor);
        }

        self::autoloadOnce($class);

        // The guard keeps is_a() from autoloading $class itself; its ancestors are already loaded.
        return (\class_exists($class, false) || \interface_exists($class, false)) && \is_a($class, $ancestor, true);
    }

    /** See {@see $autoloadAttempted}. */
    private static function autoloadOnce(string $class): void
    {
        $key = \strtolower($class);
        if (isset(self::$autoloadAttempted[$key])) {
            return;
        }

        self::$autoloadAttempted[$key] = true;

        try {
            // One autoload declares the target whatever its kind (class, interface or enum).
            \class_exists($class);
        } catch (\Throwable) {
            // Failed to load: the checks above then find nothing declared, an unknown cast.
        }
    }

    private static function resolveBaseCast(string $baseCast, bool $nullable, ?Union $originalType): ?Union
    {
        return match ($baseCast) {
            'int', 'integer' => self::makeNullable(self::integerReadType($originalType), $nullable),
            'real', 'float', 'double' => self::makeNullable(Type::getFloat(), $nullable),
            'decimal' => self::makeNullable(Type::getString(), $nullable),
            'string' => self::makeNullable(Type::getString(), $nullable),
            'bool', 'boolean' => self::makeNullable(Type::getBool(), $nullable),
            'object' => self::makeNullable(new Union([new Type\Atomic\TObjectWithProperties([])]), $nullable),
            'array', 'json' => self::makeNullable(Type::getArray(), $nullable),
            'collection' => self::makeNullable(
                new Union([new TNamedObject(IlluminateCollection::class)]),
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
            'timestamp' => self::makeNullable(self::integerReadType($originalType), $nullable),
            'encrypted' => self::makeNullable(Type::getMixed(), $nullable),
            'hashed' => self::makeNullable(Type::getString(), $nullable),
            default => null,
        };
    }

    /**
     * Read type of an int-valued cast: the column's own type when it is already all-int (keeps the
     * unsigned `int<0, max>` of migration-derived columns, including the implicit incrementing-key
     * cast), plain `int` when the column type is unknown or not an int (e.g. a string column).
     *
     * @psalm-capabilities read-props
     */
    private static function integerReadType(?Union $originalType): Union
    {
        if (!$originalType instanceof Union) {
            return Type::getInt();
        }

        foreach ($originalType->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof Type\Atomic\TInt) {
                return Type::getInt();
            }
        }

        return $originalType;
    }

    /**
     * @var array<string, Union> Per-class cache for framework Castable read types.
     *
     * `getPropertyType` fires once per model-property access during analysis; an app with many
     * `AsCollection` / `AsArrayObject` casts hits this many times. The result is purely a
     * function of `$castClass`, so we memoize the constructed `Union` once per process.
     */
    private static array $frameworkCastCache = [];

    /**
     * Framework-shipped Castable classes whose castUsing() returns null on malformed data, so the
     * read type is implicitly nullable regardless of the column's nullability.
     */
    private static function resolveFrameworkCast(string $castClass): ?Union
    {
        if (\array_key_exists($castClass, self::$frameworkCastCache)) {
            return self::$frameworkCastCache[$castClass];
        }

        $type = match ($castClass) {
            AsArrayObject::class, AsEncryptedArrayObject::class => new Union([
                new TGenericObject(EloquentArrayObject::class, [Type::getArrayKey(), Type::getMixed()]),
            ]),
            AsCollection::class, AsEncryptedCollection::class => new Union([
                new TGenericObject(IlluminateCollection::class, [Type::getArrayKey(), Type::getMixed()]),
            ]),
            AsStringable::class => new Union([new TNamedObject(IlluminateStringable::class)]),
            default => null,
        };

        if (!$type instanceof Union) {
            // Don't cache misses — the set of framework cast classes is fixed (~5 entries),
            // so the next call with the same non-matching class will hit the match's default
            // arm just as cheaply, and caching every distinct user cast class would grow
            // without bound.
            return null;
        }

        // Always nullable: each of these casts' get() returns null on missing/invalid input,
        // independent of the column's nullability. Mirrors Larastan.
        $result = Type::combineUnionTypes($type, Type::getNull());
        self::$frameworkCastCache[$castClass] = $result;

        return $result;
    }

    /**
     * Chase {@see Castable::castUsing}'s declared return type to find the underlying
     * CastsAttributes / CastsInboundAttributes implementation. We do NOT recurse
     * Castable → Castable; in practice castUsing always returns the terminal caster,
     * and a Castable chain would be pathological. Mirrors Larastan's single-hop behavior.
     */
    private static function resolveCastable(
        Codebase $codebase,
        string $castClass,
        bool $nullable,
        ?Union $originalType,
    ): ?Union {
        $method = $castClass . '::castUsing';
        if (!$codebase->methodExists($method)) {
            return null;
        }

        $returnType = $codebase->getMethodReturnType($method, $castClass);
        if (!$returnType instanceof Union) {
            return null;
        }

        foreach ($returnType->getAtomicTypes() as $atomic) {
            // `@return CastsAttributes<TGet, TSet>` — most common when castUsing returns an
            // anonymous class with explicit generics on the interface (e.g. AsCollection).
            // Strict equality on the interface itself: a concrete subclass's `@template`s
            // may not align with the interface's `TGet, TSet` ordering, so we only
            // short-circuit on the interface. Concrete generic subclasses go through the
            // TNamedObject branch below which reflects on `::get`.
            if (
                $atomic instanceof TGenericObject
                && $atomic->value === CastsAttributes::class
                && isset($atomic->type_params[0])
            ) {
                return self::makeNullable($atomic->type_params[0], $nullable);
            }

            // `@return ConcreteCast` / `@return ConcreteCast<X>` / `return new ConcreteCast;`
            // — reflect on the concrete class's `get()` (which carries the user docblock or
            // resolves the templates via `@implements CastsAttributes<X, Y>`).
            //
            // TGenericObject extends TNamedObject in Psalm, so this branch fires for both
            // shapes; the TGenericObject===CastsAttributes early-return above already
            // siphoned off the interface-itself case.
            if (
                $atomic instanceof TNamedObject
                && ClassLineage::isA($codebase, $atomic->value, CastsAttributes::class)
            ) {
                $resolved = self::resolveCastsAttributesGet($codebase, $atomic->value, $nullable);
                if ($resolved instanceof Union) {
                    return $resolved;
                }
            }

            // Castable that ultimately returns a CastsInboundAttributes — the contract
            // allows this (`@return class-string<CastsAttributes|CastsInboundAttributes>|...`).
            // Reading a write-only cast is a passthrough of the column's raw type, same as
            // the direct CastsInboundAttributes branch in resolve().
            if (
                $atomic instanceof TNamedObject
                && ClassLineage::isA($codebase, $atomic->value, CastsInboundAttributes::class)
                && !ClassLineage::isA($codebase, $atomic->value, CastsAttributes::class)
            ) {
                if ($originalType instanceof Union) {
                    return self::makeNullable($originalType, $nullable);
                }

                return self::makeNullable(Type::getMixed(), $nullable);
            }

            // `@return class-string<ConcreteCast>` (or `return ConcreteCast::class;`).
            if (
                $atomic instanceof TClassString
                && $atomic->as_type instanceof TNamedObject
            ) {
                $cls = $atomic->as_type->value;

                if (ClassLineage::isA($codebase, $cls, CastsAttributes::class)) {
                    $resolved = self::resolveCastsAttributesGet($codebase, $cls, $nullable);
                    if ($resolved instanceof Union) {
                        return $resolved;
                    }
                }

                if (
                    ClassLineage::isA($codebase, $cls, CastsInboundAttributes::class)
                    && !ClassLineage::isA($codebase, $cls, CastsAttributes::class)
                ) {
                    if ($originalType instanceof Union) {
                        return self::makeNullable($originalType, $nullable);
                    }

                    return self::makeNullable(Type::getMixed(), $nullable);
                }
            }
        }

        return null;
    }

    /**
     * Reflect on the cast class's `get()` method. The interface signature is `get(): TGet|null`,
     * so for generic implementations Psalm returns the resolved `TGet|null`; for non-generic
     * implementations users typically override `get()` with their own concrete `@return` docblock,
     * which takes precedence.
     */
    private static function resolveCastsAttributesGet(
        Codebase $codebase,
        string $castClass,
        bool $nullable,
    ): ?Union {
        $method = $castClass . '::get';
        if (!$codebase->methodExists($method)) {
            return null;
        }

        $returnType = $codebase->getMethodReturnType($method, $castClass);
        if (!$returnType instanceof Union) {
            return null;
        }

        return self::makeNullable($returnType, $nullable);
    }

    /** @psalm-external-mutation-free */
    private static function makeNullable(Union $type, bool $nullable): Union
    {
        if (!$nullable) {
            return $type;
        }

        return Type::combineUnionTypes($type, Type::getNull());
    }

    /**
     * Strip the parameter suffix from a cast string (`Money:USD` → `Money`, `decimal:2` → `decimal`).
     *
     * @psalm-pure
     */
    private static function stripParameters(string $value): string
    {
        $colon = \strpos($value, ':');
        return $colon === false ? $value : \substr($value, 0, $colon);
    }
}
