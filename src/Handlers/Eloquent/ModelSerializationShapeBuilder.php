<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Concerns\HasAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Enumerable;
use Psalm\Codebase;
use Psalm\Internal\Type\Comparator\UnionTypeComparator;
use Psalm\LaravelPlugin\Providers\ModelMetadata\AccessorInfo;
use Psalm\LaravelPlugin\Providers\ModelMetadata\AttributeAccessorInfo;
use Psalm\LaravelPlugin\Providers\ModelMetadata\CastInfo;
use Psalm\LaravelPlugin\Providers\ModelMetadata\CastShape;
use Psalm\LaravelPlugin\Providers\ModelMetadata\ColumnInfo;
use Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Union;

/**
 * Pure, registry-consuming builder for a model's serialized array shape, mirroring
 * {@see HasAttributes::attributesToArray()}. The Psalm wiring lives in {@see ModelToArrayShapeHandler}.
 *
 * Shape (`getArrayableItems` order): keys = schema columns + `$appends`, intersect `$visible` (when
 * non-empty), minus `$hidden`. A column value is the accessor's serialized type when one backs the
 * column (Laravel applies mutators before casts — {@see serializedAppendType()}), else a divergent
 * cast's serialized type ({@see serializedCastType()}), else the read type
 * ({@see ModelPropertyHandler::resolveColumnType()}); `$appends` resolve via their accessor, else `mixed`.
 * `$appends`/`$hidden`/`$visible` include their `#[Appends]`/`#[Hidden]`/`#[Visible]` PHP-attribute config
 * (merged at warm-up by {@see \Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadataRegistryBuilder}).
 *
 * OPEN shape, every key optional: query-dependent keys (aggregate/`selectRaw` aliases, `setAttribute`,
 * relations) and partial loads / runtime visibility changes mean no key is guaranteed and unknown keys
 * must not false-positive.
 *
 * Not modeled: runtime mutators (`makeHidden`/`append`/…); divergent casts other than date/backed-enum
 * (kept at read-type); collection element shapes past one level.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/923
 * @internal
 */
final class ModelSerializationShapeBuilder
{
    /**
     * Build the shape, or null to defer to the stub `array<string, mixed>` (no surviving key). No
     * schema-empty bail: `$appends` always serialize and the OPEN shape keeps unseen columns at `mixed`.
     *
     * @param class-string<Model> $modelClass
     */
    public static function build(Codebase $codebase, string $modelClass, ModelMetadata $metadata): ?Union
    {
        $columns = $metadata->schema()->all();
        $visible = $metadata->visible;
        $hidden = $metadata->hidden;

        $properties = [];

        // Attribute bag (columns) first.
        foreach ($columns as $columnName => $columnInfo) {
            if (!self::isArrayable($columnName, $visible, $hidden)) {
                continue;
            }

            $properties[$columnName] = self::columnValueType($codebase, $modelClass, $metadata, $columnName, $columnInfo)
                ->setPossiblyUndefined(true);
        }

        // Appends after, so a name clash lets the append (accessor result) win.
        foreach ($metadata->appends as $appendName) {
            if (!self::isArrayable($appendName, $visible, $hidden)) {
                continue;
            }

            $properties[$appendName] = self::appendValueType($codebase, $metadata, $appendName)
                ->setPossiblyUndefined(true);
        }

        if ($properties === []) {
            return null;
        }

        // OPEN: query-dependent keys (aliases, setAttribute, relations) fall through to mixed, not an error.
        return new Union([TKeyedArray::make($properties, fallback_params: [Type::getString(), Type::getMixed()])]);
    }

    /**
     * Laravel's `getArrayableItems`: intersect `$visible` (when non-empty), then drop `$hidden`.
     * Case-sensitive, matching Eloquent's attribute semantics.
     *
     * @param list<non-empty-string> $visible
     * @param list<non-empty-string> $hidden
     * @psalm-pure
     */
    private static function isArrayable(string $name, array $visible, array $hidden): bool
    {
        if ($visible !== [] && !\in_array($name, $visible, true)) {
            return false;
        }

        return !\in_array($name, $hidden, true);
    }

    /**
     * Serialized column type, precedence class-cast > accessor > date/backed-enum cast > read type,
     * mirroring {@see HasAttributes::mutateAttributeForArray()} (isClassCastable before the accessor) and
     * the mutator-before-other-casts order of {@see HasAttributes::attributesToArray()}. A class cast
     * keeps the read type (class-cast serialization is not modeled); else an accessor serializes via
     * {@see serializedAppendType()}; else a divergent cast via {@see serializedCastType()}; else the read
     * type ({@see ModelPropertyHandler::resolveColumnType()}).
     *
     * @param class-string<Model> $modelClass
     */
    private static function columnValueType(
        Codebase $codebase,
        string $modelClass,
        ModelMetadata $metadata,
        string $columnName,
        ColumnInfo $columnInfo,
    ): Union {
        $cast = $metadata->casts()[$columnName] ?? null;

        // A class cast (CastsAttributes/Castable) wins over a get accessor: mutateAttributeForArray()
        // applies isClassCastable() BEFORE the accessor. We don't model class-cast serialization, so such
        // a column keeps its read type whether or not it also has an accessor (the same as a class-cast
        // column without one).
        $hasClassCast = $cast instanceof CastInfo && $cast->shape->isClassCastable();

        // Otherwise the accessor wins over the (primitive/date/enum) cast and schema: Laravel applies
        // mutators before those casts and skips the cast for a mutated key.
        if (!$hasClassCast) {
            $accessor = $metadata->accessor($columnName);
            if ($accessor instanceof AccessorInfo) {
                return self::serializedAppendType($codebase, $accessor);
            }
        }

        if ($cast instanceof CastInfo) {
            $serialized = self::serializedCastType($codebase, $cast, $columnInfo);
            if ($serialized instanceof Union) {
                return $serialized;
            }
        }

        return ModelPropertyHandler::resolveColumnType($codebase, $modelClass, $columnName) ?? Type::getMixed();
    }

    /**
     * Serialized (array-side) type for casts that serialize differently than they read; null otherwise
     * (caller keeps the read type).
     *
     * @psalm-mutation-free
     */
    private static function serializedCastType(Codebase $codebase, CastInfo $cast, ColumnInfo $columnInfo): ?Union
    {
        return match ($cast->shape) {
            CastShape::DateTime => self::scalarOrNull(new TString(), $columnInfo->nullable), // Carbon -> ISO string.
            CastShape::BackedEnum => self::backedEnumValueType($codebase, $cast, $columnInfo), // backing value, not the case.
            default => null,
        };
    }

    /**
     * Backing scalar of a backed-enum cast from the enum's Psalm storage; null when the target or its
     * backing type is unresolved (best-effort).
     *
     * @psalm-mutation-free
     */
    private static function backedEnumValueType(Codebase $codebase, CastInfo $cast, ColumnInfo $columnInfo): ?Union
    {
        if ($cast->targetClass === null) {
            return null;
        }

        try {
            $enumStorage = $codebase->classlike_storage_provider->get($cast->targetClass);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $backing = match ($enumStorage->enum_type) {
            'int' => new TInt(),
            'string' => new TString(),
            default => null,
        };

        return $backing instanceof Atomic ? self::scalarOrNull($backing, $columnInfo->nullable) : null;
    }

    /** @psalm-pure */
    private static function scalarOrNull(Atomic $atomic, bool $nullable): Union
    {
        return $nullable ? new Union([$atomic, new TNull()]) : new Union([$atomic]);
    }

    /**
     * Append value type: the accessor return type mapped to its serialized form
     * ({@see serializedAppendType()}), or `mixed` when no statically known accessor backs the key.
     */
    private static function appendValueType(Codebase $codebase, ModelMetadata $metadata, string $appendName): Union
    {
        $accessor = $metadata->accessor($appendName);

        return $accessor instanceof AccessorInfo
            ? self::serializedAppendType($codebase, $accessor)
            : Type::getMixed();
    }

    /**
     * Map an accessor read type to its serialized form, per {@see HasAttributes::mutateAttributeForArray()}:
     * `Arrayable` -> `array` (both styles), and on a modern `Attribute` a `DateTimeInterface` -> `string`
     * (legacy `getXxxAttribute()` does not date-serialize). Other atomics pass through.
     */
    private static function serializedAppendType(Codebase $codebase, AccessorInfo $accessor): Union
    {
        $isModern = $accessor instanceof AttributeAccessorInfo;

        $changed = false;
        $atomics = [];
        foreach ($accessor->returnType->getAtomicTypes() as $atomic) {
            $mapped = self::serializedAtomic($codebase, $atomic, $isModern);
            if ($mapped !== $atomic) {
                $changed = true;
            }

            $atomics[] = $mapped;
        }

        // One-for-one with the (non-empty) source atomics, so non-empty here.
        return $changed ? new Union($atomics) : $accessor->returnType;
    }

    /**
     * Serialized form of one top-level atomic: a generic `Enumerable` -> `array<TKey, …>` (each
     * `Arrayable` element collapsed one level), any other `Arrayable` -> `array<array-key, mixed>`, a
     * modern `Attribute` `DateTimeInterface` -> `string`; everything else (scalars, null, legacy date) as-is.
     */
    private static function serializedAtomic(Codebase $codebase, Atomic $atomic, bool $isModern): Atomic
    {
        if (!$atomic instanceof TNamedObject) {
            return $atomic;
        }

        $single = new Union([$atomic]);

        // Enumerable::toArray() keeps keys, maps each element via Arrayable::toArray(); needs a declared value.
        if ($atomic instanceof TGenericObject
            && isset($atomic->type_params[1])
            && UnionTypeComparator::isContainedBy($codebase, $single, new Union([new TNamedObject(Enumerable::class)]))) {
            return new TArray([
                $atomic->type_params[0],
                self::collapseArrayableValues($codebase, $atomic->type_params[1]),
            ]);
        }

        if (UnionTypeComparator::isContainedBy($codebase, $single, new Union([new TNamedObject(Arrayable::class)]))) {
            return new TArray([Type::getArrayKey(), Type::getMixed()]);
        }

        if ($isModern
            && UnionTypeComparator::isContainedBy($codebase, $single, new Union([new TNamedObject(\DateTimeInterface::class)]))) {
            return new TString();
        }

        return $atomic;
    }

    /**
     * Collapse one level of collection element types: an `Arrayable` element -> `array`, everything else
     * kept — including a `DateTimeInterface`, which `Enumerable::toArray()` (unlike a modern `Attribute`)
     * does NOT date-serialize.
     */
    private static function collapseArrayableValues(Codebase $codebase, Union $value): Union
    {
        $arrayable = new Union([new TNamedObject(Arrayable::class)]);

        $changed = false;
        $atomics = [];
        foreach ($value->getAtomicTypes() as $atomic) {
            $mapped = $atomic instanceof TNamedObject
                && UnionTypeComparator::isContainedBy($codebase, new Union([$atomic]), $arrayable)
                ? new TArray([Type::getArrayKey(), Type::getMixed()])
                : $atomic;
            if ($mapped !== $atomic) {
                $changed = true;
            }

            $atomics[] = $mapped;
        }

        // One-for-one with the (non-empty) source atomics, so non-empty here.
        return $changed ? new Union($atomics) : $value;
    }
}
