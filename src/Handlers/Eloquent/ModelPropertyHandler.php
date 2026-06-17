<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn;
use Psalm\LaravelPlugin\Providers\ModelMetadata\ColumnInfo;
use Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata;
use Psalm\LaravelPlugin\Providers\ModelMetadataRegistry;
use Psalm\LaravelPlugin\Providers\SchemaStateProvider;
use Psalm\Plugin\EventHandler\Event\PropertyExistenceProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyVisibilityProviderEvent;
use Psalm\Type;
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
 * by {@see \Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadataRegistryBuilder::computeCasts()}.
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
        // Defensive vs. `getPropertyType`: the caller's FQCN can come from mixin
        // template inference and may not be in the storage provider.
        try {
            $classStorage = $codebase->classlike_storage_provider->get($fqClasslikeName);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $propertyType = $classStorage->pseudo_property_get_types['$' . $columnName] ?? null;
        if ($propertyType instanceof Union) {
            return $propertyType;
        }

        return self::columnTypeFromRegistry($fqClasslikeName, $columnName);
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

        $metadata = ModelMetadataRegistry::for($fqClasslikeName);
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

        return self::mapColumnType($column);
    }

    /**
     * Resolve all migration-inferred columns for a model.
     *
     * Reads {@see SchemaStateProvider} directly (not the registry): the sole caller is
     * {@see \Psalm\LaravelPlugin\Handlers\Eloquent\ModelRegistrationHandler::registerWriteTypesForColumns()},
     * which runs DURING `AfterCodebasePopulated` — interleaved with the registry warm-up
     * for the same loop — so the registry entry for this model may not exist yet. It only
     * needs the column NAMES (write types are registered as `mixed`), so the schema-state
     * read is sufficient and avoids a warm-up ordering dependency.
     *
     * @return array<string, SchemaColumn>
     */
    public static function resolveAllColumns(string $fqClasslikeName): array
    {
        $schema = SchemaStateProvider::getSchema();
        if (!$schema instanceof \Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator) {
            return [];
        }

        $tableName = self::resolveTableName($fqClasslikeName);
        if ($tableName === null || !isset($schema->tables[$tableName])) {
            return [];
        }

        return $schema->tables[$tableName]->columns;
    }

    /**
     * @param class-string<Model> $fqClasslikeName
     * @psalm-external-mutation-free
     */
    private static function schemaHasColumn(string $fqClasslikeName, string $propertyName): bool
    {
        $metadata = ModelMetadataRegistry::for($fqClasslikeName);
        if (!$metadata instanceof ModelMetadata) {
            return false;
        }

        return $metadata->schema()->has($propertyName);
    }

    /** @var array<string, ?string> model class → table name cache (used only by resolveAllColumns). */
    private static array $tableNameCache = [];

    private static function resolveTableName(string $fqClasslikeName): ?string
    {
        if (\array_key_exists($fqClasslikeName, self::$tableNameCache)) {
            return self::$tableNameCache[$fqClasslikeName];
        }

        if (!\is_a($fqClasslikeName, Model::class, true)) {
            return self::$tableNameCache[$fqClasslikeName] = null;
        }

        try {
            $reflection = new \ReflectionClass($fqClasslikeName);
            if ($reflection->isAbstract()) {
                return self::$tableNameCache[$fqClasslikeName] = null;
            }

            $instance = $reflection->newInstanceWithoutConstructor();
            if (!$instance instanceof Model) {
                return self::$tableNameCache[$fqClasslikeName] = null;
            }

            $tableName = $instance->getTable();
        } catch (\ReflectionException) {
            return self::$tableNameCache[$fqClasslikeName] = null;
        }

        return self::$tableNameCache[$fqClasslikeName] = $tableName;
    }

    /**
     * Map a column to its Psalm read type, applying nullability. The base mapping is shared
     * with {@see mapColumnBaseType} so the cast warm-up (which bakes the CastsInboundAttributes
     * passthrough type) and the schema read path agree on the column's intrinsic type.
     */
    private static function mapColumnType(ColumnInfo $column): Union
    {
        $type = self::mapColumnBaseType($column);

        if ($column->nullable) {
            $type = Type::combineUnionTypes($type, Type::getNull());
        }

        return $type;
    }

    /**
     * Non-nullable base mapping for a schema column. Factored out so the registry builder can
     * obtain the column's intrinsic Psalm type for {@see \Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes}
     * casts (whose read path is a passthrough of the raw DB type) while still letting the cast
     * resolver decide how to apply nullability on the final union.
     *
     * @internal called by {@see \Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadataRegistryBuilder}
     */
    public static function mapColumnBaseType(ColumnInfo $column): Union
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

    /** @var array<string, bool> Cache for hasNativeProperty() keyed by "class::property" */
    private static array $nativePropertyCache = [];

    /**
     * Uses property_exists() instead of Reflection — cheaper and avoids exception overhead
     * on the non-existence path. Cached because this fires up to 3× per property access
     * (doesPropertyExist, isPropertyVisible, getPropertyType).
     *
     * @param class-string $fqcn
     * @psalm-external-mutation-free
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
