<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Concerns\HasAttributes;
use Illuminate\Database\Eloquent\Model;
use Psalm\Codebase;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Providers\ModelMetadata\AccessorInfo;
use Psalm\LaravelPlugin\Providers\ModelMetadata\CastInfo;
use Psalm\LaravelPlugin\Providers\ModelMetadata\CastShape;
use Psalm\LaravelPlugin\Providers\ModelMetadata\ColumnInfo;
use Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata;
use Psalm\LaravelPlugin\Providers\ModelMetadataRegistry;
use Psalm\LaravelPlugin\Util\EloquentModelMethods;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Union;

/**
 * Infers a precise array shape for a model's serialization methods,
 * {@see HasAttributes::attributesToArray()} and {@see Model::toArray()}.
 *
 * Without this handler both return Laravel's loose `array<string, mixed>`. With it the
 * inferred type names each serialized key:
 *
 *   array{id?: int, name?: string, created_at?: string|null, full_name?: string, ...<string, mixed>}
 *
 * Shape construction (mirrors `HasAttributes::attributesToArray()`):
 *  - Keys are the schema columns plus the `$appends` list, intersected with `$visible` when
 *    `$visible` is declared non-empty, then minus `$hidden` (Laravel's `getArrayableItems`
 *    order, so a key listed in both loses).
 *  - Column value types come from {@see ModelPropertyHandler::resolveColumnType()} (the
 *    `@property` → cast → schema chain), EXCEPT casts whose serialized form diverges from the
 *    property-read form: a `datetime`/`date` cast reads as `Carbon` but serializes to an ISO
 *    string, and a backed-enum cast serializes to its backing scalar. Those are mapped to their
 *    serialized type here (see {@see serializedCastType()}).
 *  - Appended value types come from the matching accessor's return type, or `mixed` when no
 *    statically known accessor backs the key.
 *
 * Both methods produce an OPEN shape (`array{known?: T, …, ...<string, mixed>}`). The attribute bag
 * is query-dependent: `withCount()`/`withSum()` aggregate aliases (`posts_count`), `selectRaw()`
 * aliases, and `setAttribute()` add keys that are neither columns nor `$appends`, and `toArray()`
 * additionally folds in loaded relations. Sealing the shape would false-positive on those legitimate
 * keys, so it names the known keys precisely and lets the rest fall through to `mixed`.
 *
 * EVERY key is optional. A partial column load — `Model::find($id, ['id'])`, `select('id')`,
 * or `makeHidden()`/`setVisible()` at runtime — serializes only the attributes actually present
 * on the instance, so no single key is guaranteed at a call site. Marking all keys
 * possibly-undefined keeps the shape sound under those cases rather than over-claiming presence.
 *
 * Known limitations (documented, not bugs):
 *  - Runtime mutators (`makeHidden()`/`makeVisible()`/`append()`/`setAppends()`/…) change the set
 *    at runtime and are invisible here; only declared `$hidden`/`$visible`/`$appends` are modeled.
 *  - Casts other than date/backed-enum that serialize differently than they read (a non-backed
 *    enum, `AsEnumCollection`, an `Arrayable`/`Collection` custom cast) keep their read-type.
 *  - A real column that also has an accessor keeps its column/cast type, not the accessor type
 *    (the issue scopes accessor value types to `$appends`).
 *
 * Registered per concrete Model class by {@see ModelRegistrationHandler} because Psalm's provider
 * lookup uses exact class-name matching. Abstract bases have an empty schema and fall through the
 * gate below, returning null.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/923
 * @internal
 */
final class ModelToArrayShapeHandler
{
    private const ATTRIBUTES_TO_ARRAY = 'attributestoarray';

    private const TO_ARRAY = 'toarray';

    public static function getReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $method = $event->getMethodNameLowercase();
        if ($method !== self::ATTRIBUTES_TO_ARRAY && $method !== self::TO_ARRAY) {
            return null;
        }

        $codebase = $event->getSource()->getCodebase();

        /** @var class-string<Model> $modelClass */
        $modelClass = $event->getFqClasslikeName();

        // Bail when the model (or a trait) overrides the serializer — an override may return a shape
        // different from Laravel's. Model::toArray() delegates to attributesToArray(), so a toArray()
        // shape is valid only when BOTH are the framework's: an attributesToArray()-only override
        // still changes toArray()'s output. Mirrors ModelAttributeSubsetHandler's override bail.
        if (!self::isFrameworkSerializer($codebase, $modelClass, self::ATTRIBUTES_TO_ARRAY)) {
            return null;
        }

        if ($method === self::TO_ARRAY && !self::isFrameworkSerializer($codebase, $modelClass, self::TO_ARRAY)) {
            return null;
        }

        return self::buildShape($codebase, $modelClass);
    }

    /**
     * Build the serialized-attribute shape for a model, or null to defer to the stub's
     * `array<string, mixed>`. Always an OPEN shape (`array{known?: T, …, ...<string, mixed>}`): the
     * runtime attribute bag carries query-dependent extra keys (aggregate/`selectRaw` aliases,
     * `setAttribute`, and — for `toArray()` — relations), so the known keys are named precisely and
     * the rest fall through to `mixed`.
     *
     * @param class-string<Model> $modelClass
     * @internal Exposed for unit testing against a warmed registry; the event flow uses
     *           {@see getReturnType()}.
     */
    public static function buildShape(Codebase $codebase, string $modelClass): ?Union
    {
        $metadata = ModelMetadataRegistry::for($modelClass);
        if (!$metadata instanceof ModelMetadata) {
            return null;
        }

        // Schema-empty gate: with no parsed columns (migrations disabled or the table unparsed) the
        // attribute set is unknowable, and a shape built from $appends alone would omit every real
        // column. Defer to the stub instead. Same gate #1167 relies on.
        $columns = $metadata->schema()->all();
        if ($columns === []) {
            return null;
        }

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

            $properties[$appendName] = self::appendValueType($metadata, $appendName)
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
     * Appended value type: the matching accessor's return type, or `mixed` when no statically
     * known accessor backs the key. The accessor map is keyed by the separator-collapsed
     * lowercase identity, so `full_name` / `fullName` / `fullname` all resolve to one accessor.
     *
     * @psalm-mutation-free
     */
    private static function appendValueType(ModelMetadata $metadata, string $appendName): Union
    {
        $key = EloquentModelMethods::accessorPropertyKey($appendName);
        if ($key === null) {
            return Type::getMixed();
        }

        $accessor = $metadata->accessors()[$key] ?? null;

        return $accessor instanceof AccessorInfo ? $accessor->returnType : Type::getMixed();
    }

    /**
     * True when `$modelClass::$method` still resolves to Laravel's own implementation
     * (`HasAttributes::attributesToArray` / `Model::toArray`), i.e. no override on the concrete
     * class or an intervening trait.
     *
     * @param lowercase-string $method
     * @psalm-mutation-free
     */
    private static function isFrameworkSerializer(Codebase $codebase, string $modelClass, string $method): bool
    {
        $declaring = $codebase->methods->getDeclaringMethodId(new MethodIdentifier($modelClass, $method));
        if (!$declaring instanceof MethodIdentifier) {
            return false;
        }

        $declaringClass = \strtolower($declaring->fq_class_name);

        return $declaringClass === \strtolower(HasAttributes::class)
            || $declaringClass === \strtolower(Model::class);
    }
}
