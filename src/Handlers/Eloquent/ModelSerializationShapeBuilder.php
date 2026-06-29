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
use Psalm\LaravelPlugin\Util\EloquentModelMethods;
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
 * Builds the precise array shape of a model's serialization output, owning Laravel's
 * {@see HasAttributes::attributesToArray()} ordering and per-attribute serialized-type mapping. The
 * Psalm event wiring lives in {@see ModelToArrayShapeHandler}; this class is a pure, registry-consuming
 * builder so the serialization rules stay in one place, testable in isolation.
 *
 * Shape construction (mirrors `HasAttributes::attributesToArray()`):
 *  - Keys are the schema columns plus the `$appends` list, intersected with `$visible` when
 *    `$visible` is declared non-empty, then minus `$hidden` (Laravel's `getArrayableItems` order, so
 *    a key listed in both loses).
 *  - Column value types come from {@see ModelPropertyHandler::resolveColumnType()} (the
 *    `@property` → cast → schema chain), EXCEPT casts whose serialized form diverges from the
 *    property-read form: a `datetime`/`date` cast reads as `Carbon` but serializes to an ISO string,
 *    and a backed-enum cast serializes to its backing scalar (see {@see serializedCastType()}).
 *  - Appended value types come from the matching accessor's return type, mapped to the serialized
 *    form when it diverges (a generic collection → `array<TKey, …>`, any other `Arrayable` →
 *    `array`, a modern `Attribute` date → `string`; a legacy date accessor keeps its object), or
 *    `mixed` when no statically known accessor backs the key. See {@see serializedAppendType()}.
 *
 * The result is an OPEN shape (`array{known?: T, …, ...<string, mixed>}`). The attribute bag is
 * query-dependent: `withCount()`/`withSum()` aggregate aliases (`posts_count`), `selectRaw()`
 * aliases, and `setAttribute()` add keys that are neither columns nor `$appends`, and `toArray()`
 * additionally folds in loaded relations. Sealing the shape would false-positive on those legitimate
 * keys, so it names the known keys precisely and lets the rest fall through to `mixed`.
 *
 * EVERY key is optional. A partial column load — `Model::find($id, ['id'])`, `select('id')`, or
 * `makeHidden()`/`setVisible()` at runtime — serializes only the attributes actually present on the
 * instance, so no single key is guaranteed at a call site. Marking all keys possibly-undefined keeps
 * the shape sound under those cases rather than over-claiming presence.
 *
 * Known limitations (documented, not bugs):
 *  - Runtime mutators (`makeHidden()`/`makeVisible()`/`append()`/`setAppends()`/…) change the set at
 *    runtime and are invisible here; only declared `$hidden`/`$visible`/`$appends` are modeled.
 *  - Casts other than date/backed-enum that serialize differently than they read (a non-backed enum,
 *    `AsEnumCollection`, an `Arrayable`/`Collection` custom cast) keep their read-type.
 *  - An appended collection maps to `array<TKey, V>` collapsing one level (each `Arrayable` element
 *    becomes `array<array-key, mixed>`); deeper nesting and non-collection `Arrayable` value shapes
 *    are not modeled.
 *  - A real column that also has an accessor keeps its column/cast type, not the accessor type (the
 *    issue scopes accessor value types to `$appends`).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/923
 * @internal
 */
final class ModelSerializationShapeBuilder
{
    /**
     * Build the serialized-attribute shape for a model, or null to defer to the stub's
     * `array<string, mixed>` (when neither columns nor appends yield a surviving key).
     *
     * No schema-empty bail: a model with no parsed columns (migrations disabled or the table
     * unparsed) can still expose a known serialized surface through `$appends`, which Laravel always
     * serializes. The shape stays OPEN, so real columns we cannot see fall through to `mixed` rather
     * than being dropped — strictly more than the stub, and still sound.
     *
     * @param class-string<Model> $modelClass
     */
    public static function build(Codebase $codebase, string $modelClass, ModelMetadata $metadata): ?Union
    {
        $columns = $metadata->schema()->all();
        $visible = $metadata->visible;
        $hidden = $metadata->hidden;

        $properties = [];

        // Columns first — Laravel serializes the attribute bag (getArrayableAttributes) before appends.
        foreach ($columns as $columnName => $columnInfo) {
            if (!self::isArrayable($columnName, $visible, $hidden)) {
                continue;
            }

            $properties[$columnName] = self::columnValueType($codebase, $modelClass, $metadata, $columnName, $columnInfo)
                ->setPossiblyUndefined(true);
        }

        // Appends are added after the attribute bag, so a name clash lets the append win (its value
        // is the accessor result, matching the runtime `$attributes[$key] = mutateAttributeForArray()`).
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

        // Open the shape: the attribute bag may hold query-dependent keys (aggregate/selectRaw
        // aliases, setAttribute, relations) that are neither columns nor $appends. Sealing would
        // false-positive on those, so unknown keys resolve to mixed instead of an offset error.
        return new Union([TKeyedArray::make($properties, fallback_params: [Type::getString(), Type::getMixed()])]);
    }

    /**
     * Laravel's `getArrayableItems`: intersect with `$visible` when it is non-empty, then drop
     * `$hidden`. Comparisons are case-sensitive, matching Eloquent's runtime attribute semantics.
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
     * Serialized value type for a column: the date/backed-enum serialization override when it
     * applies, otherwise the property-read column type ({@see ModelPropertyHandler::resolveColumnType}).
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
        if ($cast instanceof CastInfo) {
            $serialized = self::serializedCastType($codebase, $cast, $columnInfo);
            if ($serialized instanceof Union) {
                return $serialized;
            }
        }

        return ModelPropertyHandler::resolveColumnType($codebase, $modelClass, $columnName) ?? Type::getMixed();
    }

    /**
     * The serialized (array-side) type for the casts whose serialized form differs from their
     * property-read form. Returns null for every other cast, so the caller keeps the read-type.
     *
     * @psalm-mutation-free
     */
    private static function serializedCastType(Codebase $codebase, CastInfo $cast, ColumnInfo $columnInfo): ?Union
    {
        return match ($cast->shape) {
            // date/datetime/immutable_* read as Carbon but serialize via serializeDate() to an
            // ISO-8601 string.
            CastShape::DateTime => self::scalarOrNull(new TString(), $columnInfo->nullable),
            // A backed enum serializes to its backing value (getStorableEnumValue), not the case.
            CastShape::BackedEnum => self::backedEnumValueType($codebase, $cast, $columnInfo),
            default => null,
        };
    }

    /**
     * Backing scalar of a backed-enum cast, read from the enum's Psalm storage. Returns null
     * (defer to the read-type) when the target is missing or its backing type is unresolved —
     * `CastShape::BackedEnum` is best-effort (see its docblock).
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
     * Appended value type: the matching accessor's return type mapped to its serialized form, or
     * `mixed` when no statically known accessor backs the key. The accessor map is keyed by the
     * separator-collapsed lowercase identity, so `full_name` / `fullName` / `fullname` all resolve.
     *
     * The registry captured the accessor's READ type, but an append is serialized through
     * {@see HasAttributes::mutateAttributeForArray()}: an `Arrayable` (a Collection, a related
     * Model, …) becomes its `array` form, and a modern `Attribute` accessor converts a
     * `DateTimeInterface` to an ISO string. {@see serializedAppendType()} maps those to the
     * serialized type; a legacy `getXxxAttribute()` does not convert dates, so a legacy date accessor
     * keeps its object.
     */
    private static function appendValueType(Codebase $codebase, ModelMetadata $metadata, string $appendName): Union
    {
        $key = EloquentModelMethods::accessorPropertyKey($appendName);
        if ($key === null) {
            return Type::getMixed();
        }

        $accessor = $metadata->accessors()[$key] ?? null;
        if (!$accessor instanceof AccessorInfo) {
            return Type::getMixed();
        }

        return self::serializedAppendType($codebase, $accessor);
    }

    /**
     * Map an appended accessor's READ type to its SERIALIZED type, mirroring
     * {@see HasAttributes::mutateAttributeForArray()}: an `Arrayable` atom becomes `array` (for BOTH
     * accessor styles, via `$value->toArray()`), and on a modern `Attribute` accessor a
     * `DateTimeInterface` atom becomes a `string` (via `serializeDate()`). A legacy `getXxxAttribute()`
     * does NOT convert dates, so the date map is gated on the accessor being attribute-style. Other
     * atomics (scalars, arrays, null, non-Arrayable objects) serialize as-is. The `Arrayable` array's
     * inner shape is not modeled (`array<array-key, mixed>`).
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

        // $atomics is one-for-one with the (non-empty) source atomics, so it is non-empty here.
        return $changed ? new Union($atomics) : $accessor->returnType;
    }

    /**
     * Serialized form of a single top-level append atomic:
     *  - a Laravel `Enumerable` (Collection / LazyCollection / Eloquent Collection) with a declared
     *    value type → `array<TKey, …>`, keeping the key type and collapsing each `Arrayable` element
     *    one level (the element's own recursive shape is not modeled);
     *  - any other `Arrayable` (a related Model, a bare or custom collection) → `array<array-key, mixed>`;
     *  - on a modern `Attribute` accessor only, a `DateTimeInterface` → `string` (`serializeDate()`).
     * Everything else (scalars, arrays, null, a legacy date object) serializes as-is.
     */
    private static function serializedAtomic(Codebase $codebase, Atomic $atomic, bool $isModern): Atomic
    {
        if (!$atomic instanceof TNamedObject) {
            return $atomic;
        }

        $single = new Union([$atomic]);

        // Enumerable::toArray() keeps the keys and maps each element through Arrayable::toArray(), so a
        // generic collection serializes to array<TKey, serialized(TValue)>. Requires a declared TValue.
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
     * Collapse one level of `Enumerable` element types: an `Arrayable` element serializes to an
     * `array` (inner shape unmodeled); everything else is kept — including a `DateTimeInterface`,
     * which `Enumerable::toArray()` (unlike a modern `Attribute`) does NOT date-serialize. Used for
     * the value type of a serialized collection.
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

        // $atomics is one-for-one with the (non-empty) source atomics, so it is non-empty here.
        return $changed ? new Union($atomics) : $value;
    }
}
