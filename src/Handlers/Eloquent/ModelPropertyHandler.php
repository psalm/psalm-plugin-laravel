<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ColumnInfo;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistry;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\ColumnTypeMapper;
use Psalm\Plugin\EventHandler\Event\PropertyExistenceProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyVisibilityProviderEvent;
use Psalm\Type\Union;

/**
 * Provides property existence, visibility, and type information for Eloquent model
 * attributes based on migration schema and cast definitions.
 *
 * Resolution priority:
 * 1. User-defined @property PHPDoc (detected via pseudo_property_get_types) → defers (returns null)
 * 2. Accessor method (handled by ModelPropertyAccessorHandler) → defers (returns null)
 * 3. Cast override → CastInfo::$psalmType (pre-resolved at warm-up)
 * 4. Schema column type → mapped Psalm type
 *
 * Phase 1 of the registry migration: the column/cast DATA now comes from
 * {@see ModelMetadataRegistry::for()} (warmed up during `AfterCodebasePopulated`)
 * instead of the per-handler lazy caches it used before. The SQL-type → Psalm-type
 * MAPPING stays here, reading {@see ColumnInfo} instead of {@see SchemaColumn}: the
 * schema-mapping path produces the same types the pre-registry handler did. The cast
 * path additionally corrects two pre-registry bugs — the SoftDeletes `DELETED_AT`
 * column-override and the spurious UUID/ULID primary-key `int` cast — baked at warm-up
 * by {@see \Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistryBuilder::computeCasts()}.
 *
 * @internal
 */
final class ModelPropertyHandler
{
    public static function doesPropertyExist(PropertyExistenceProviderEvent $event): ?bool
    {
        if (!$event->isReadMode()) {
            return null;
        }

        $source = $event->getSource();
        if (!$source instanceof \Psalm\StatementsSource) {
            return null;
        }

        /** @var class-string<Model> $fqClasslikeName */
        $fqClasslikeName = $event->getFqClasslikeName();
        $propertyName = $event->getPropertyName();

        // Skip native PHP properties (like $hidden, $casts, etc.)
        if (self::hasNativeProperty($fqClasslikeName, $propertyName)) {
            return null;
        }

        // Defer to user @property PHPDoc
        $classStorage = $source->getCodebase()->classlike_storage_provider->get($fqClasslikeName);
        if (isset($classStorage->pseudo_property_get_types['$' . $propertyName])) {
            return null;
        }

        return self::schemaHasColumn($fqClasslikeName, $propertyName) ? true : null;
    }

    public static function isPropertyVisible(PropertyVisibilityProviderEvent $event): ?bool
    {
        if (!$event->isReadMode()) {
            return null;
        }

        /** @var class-string<Model> $fqClasslikeName */
        $fqClasslikeName = $event->getFqClasslikeName();
        $propertyName = $event->getPropertyName();

        if (self::hasNativeProperty($fqClasslikeName, $propertyName)) {
            return null;
        }

        $codebase = $event->getSource()->getCodebase();
        $classStorage = $codebase->classlike_storage_provider->get($fqClasslikeName);
        if (isset($classStorage->pseudo_property_get_types['$' . $propertyName])) {
            return null;
        }

        return self::schemaHasColumn($fqClasslikeName, $propertyName) ? true : null;
    }

    public static function getPropertyType(PropertyTypeProviderEvent $event): ?Union
    {
        $source = $event->getSource();
        if (!$source instanceof \Psalm\StatementsSource || !$event->isReadMode()) {
            return null;
        }

        /** @var class-string<Model> $fqClasslikeName */
        $fqClasslikeName = $event->getFqClasslikeName();
        $propertyName = $event->getPropertyName();

        // Skip native properties
        if (self::hasNativeProperty($fqClasslikeName, $propertyName)) {
            return null;
        }

        $codebase = $source->getCodebase();

        // Defer to user @property PHPDoc
        $classStorage = $codebase->classlike_storage_provider->get($fqClasslikeName);
        if (isset($classStorage->pseudo_property_get_types['$' . $propertyName])) {
            return null;
        }

        return self::columnTypeFromRegistry($fqClasslikeName, $propertyName);
    }

    /**
     * Public resolver mirroring {@see getPropertyType} (user `@property` → cast → schema),
     * for handlers that need column types outside `PropertyTypeProviderEvent` (e.g.
     * {@see \Psalm\LaravelPlugin\Handlers\Eloquent\BuilderAggregateHandler}).
     *
     * Deliberately does NOT replicate {@see getPropertyType}'s `hasNativeProperty()` /
     * `isReadMode()` filters — callers pass a known column name in a read context, and
     * the native-property skip would wrongly suppress a column that shadows a framework
     * property name. Keep this guard set distinct from the property-provider hooks.
     *
     * @param class-string<Model> $fqClasslikeName
     */
    public static function resolveColumnType(
        \Psalm\Codebase $codebase,
        string $fqClasslikeName,
        string $columnName,
    ): ?Union {
        $propertyType = self::declaredPropertyType($codebase, $fqClasslikeName, $columnName);
        if ($propertyType instanceof Union) {
            return $propertyType;
        }

        return self::columnTypeFromRegistry($fqClasslikeName, $columnName);
    }

    /**
     * Explicit declarations only, in {@see resolveColumnType} order: user `@property`, then an explicit
     * cast. Unlike {@see resolveColumnType} a cast counts even when the column is not in the model's
     * own schema, and a plain schema type never does: for a `using()` pivot, whose columns live in the
     * relation table, this lets callers weigh a declaration against rules (date casts) that outrank
     * the schema but not an explicit declaration.
     *
     * @param class-string<Model> $fqClasslikeName
     */
    public static function resolveExplicitColumnType(
        \Psalm\Codebase $codebase,
        string $fqClasslikeName,
        string $columnName,
    ): ?Union {
        $propertyType = self::declaredPropertyType($codebase, $fqClasslikeName, $columnName);
        if ($propertyType instanceof Union) {
            return $propertyType;
        }

        $cast = self::castMetadata($fqClasslikeName)?->casts()[$columnName] ?? null;

        return $cast?->psalmType;
    }

    /**
     * User `@property` type. The caller's FQCN can come from mixin template inference and may not be
     * in the storage provider, hence the defensive catch (vs. {@see getPropertyType}).
     *
     * @param class-string<Model> $fqClasslikeName
     * @psalm-capabilities read-props
     */
    private static function declaredPropertyType(
        \Psalm\Codebase $codebase,
        string $fqClasslikeName,
        string $columnName,
    ): ?Union {
        try {
            $classStorage = $codebase->classlike_storage_provider->get($fqClasslikeName);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $classStorage->pseudo_property_get_types['$' . $columnName] ?? null;
    }

    /**
     * Registry entry whose cast inventory is authoritative; null when not warmed up or incomplete
     * (an incomplete inventory cannot prove that a column is uncast).
     *
     * @param class-string<Model> $fqClasslikeName
     */
    private static function castMetadata(string $fqClasslikeName): ?ModelMetadata
    {
        $metadata = ModelMetadataRegistry::for($fqClasslikeName);
        if (!$metadata instanceof ModelMetadata || !$metadata->isComplete(ModelMetadata::SECTION_CASTS)) {
            return null;
        }

        return $metadata;
    }

    /**
     * Schema-only column type, ignoring `@property` and casts: aggregate accessors
     * (`withMin()`, `withSum()`, ...) hold the raw driver value, so a datetime column is a
     * string here even when the model casts it to Carbon.
     *
     * @param class-string<Model> $fqClasslikeName
     */
    public static function resolveRawColumnType(string $fqClasslikeName, string $columnName): ?Union
    {
        $column = ModelMetadataRegistry::for($fqClasslikeName)?->schema()->column($columnName);

        return $column instanceof ColumnInfo ? ColumnTypeMapper::mapBaseType($column) : null;
    }

    /**
     * Registry-backed column type: cast override wins over the schema mapping. Shared by
     * {@see getPropertyType} and {@see resolveColumnType} so the two read paths cannot drift
     * (pre-registry they shared `resolveColumn()` + `resolveCasts()`). Returns null when the
     * column name is empty, the model was not warmed up, or the column is not migration-known.
     *
     * {@see CastInfo::$psalmType} already incorporates column nullability AND the
     * CastsInboundAttributes passthrough base type (baked at warm-up), so a cast hit is
     * returned verbatim — CastResolver is never re-run here.
     *
     * @param class-string<Model> $fqClasslikeName
     */
    private static function columnTypeFromRegistry(string $fqClasslikeName, string $columnName): ?Union
    {
        // Empty names can't match anything — bail before touching the registry.
        if ($columnName === '') {
            return null;
        }

        // An incomplete cast inventory cannot prove that a schema column is uncast.
        $metadata = self::castMetadata($fqClasslikeName);
        if (!$metadata instanceof ModelMetadata) {
            return null;
        }

        // Exact-case lookup matches Eloquent's case-sensitive attribute semantics.
        $column = $metadata->schema()->column($columnName);
        if (!$column instanceof ColumnInfo) {
            return null;
        }

        $casts = $metadata->casts();
        if (isset($casts[$columnName])) {
            return $casts[$columnName]->psalmType;
        }

        return ColumnTypeMapper::mapColumnType($column);
    }

    /**
     * Resolve all migration-inferred columns for a model.
     *
     * Reads the warmed registry: the sole caller is
     * {@see \Psalm\LaravelPlugin\Handlers\Eloquent\ModelRegistrationHandler::registerWriteTypesForColumns()},
     * which runs after the model's registry entry has been warmed. Using that snapshot is
     * important because warm-up applies Laravel's runtime model configuration, including
     * Laravel 13's `#[Table]` attribute, before resolving the table schema.
     *
     * @param class-string<Model> $fqClasslikeName
     * @return array<non-empty-string, ColumnInfo>
     */
    public static function resolveAllColumns(string $fqClasslikeName): array
    {
        $metadata = ModelMetadataRegistry::for($fqClasslikeName);
        if (!$metadata instanceof ModelMetadata) {
            return [];
        }

        return $metadata->schema()->all();
    }

    /**
     * @param class-string<Model> $fqClasslikeName
     */
    private static function schemaHasColumn(string $fqClasslikeName, string $propertyName): bool
    {
        $metadata = ModelMetadataRegistry::for($fqClasslikeName);
        if (!$metadata instanceof ModelMetadata) {
            return false;
        }

        return $metadata->schema()->has($propertyName);
    }

    /** @var array<string, bool> Cache for hasNativeProperty() keyed by "class::property" */
    private static array $nativePropertyCache = [];

    public static function reset(): void
    {
        self::$nativePropertyCache = [];
    }

    /**
     * Uses property_exists() instead of Reflection — cheaper and avoids exception overhead
     * on the non-existence path. Cached because this fires up to 3× per property access
     * (doesPropertyExist, isPropertyVisible, getPropertyType).
     *
     * @param class-string $fqcn
     */
    private static function hasNativeProperty(string $fqcn, string $propertyName): bool
    {
        $key = $fqcn . '::' . $propertyName;

        if (\array_key_exists($key, self::$nativePropertyCache)) {
            return self::$nativePropertyCache[$key];
        }

        $result = \property_exists($fqcn, $propertyName);
        self::$nativePropertyCache[$key] = $result;

        return $result;
    }
}
