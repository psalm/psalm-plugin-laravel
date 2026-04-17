<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn;
use Psalm\LaravelPlugin\Providers\ModelMetadata\ColumnInfo;
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
 * 3. Cast override → CastResolver type
 * 4. Schema column type → mapped Psalm type
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

        // Empty property names can't match anything — bail before touching the registry.
        if ($propertyName === '') {
            return null;
        }

        $metadata = ModelMetadataRegistry::for($fqClasslikeName);
        if (!$metadata instanceof \Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata) {
            return null;
        }

        // Exact-case lookup matches Eloquent's case-sensitive attribute semantics
        // (and the pre-registry behavior this refactor preserves).
        $column = $metadata->schema()->column($propertyName);
        if (!$column instanceof ColumnInfo) {
            return null;
        }

        // Cast override wins over schema type. CastInfo::$psalmType already incorporates
        // column nullability, so the consumer just returns it.
        $casts = $metadata->casts();
        if (isset($casts[$propertyName])) {
            return $casts[$propertyName]->psalmType;
        }

        return self::mapSqlTypeToPsalmType($column);
    }

    /**
     * Resolve all migration-inferred columns for a model.
     *
     * Public for ModelRegistrationHandler::registerWriteTypesForColumns(), which runs
     * during `AfterCodebasePopulated` — BEFORE the registry warm-up for the current
     * model has completed. It therefore reads from {@see SchemaStateProvider} directly
     * instead of via the registry.
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
        if (!$metadata instanceof \Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata) {
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

    private static function mapSqlTypeToPsalmType(ColumnInfo $column): Union
    {
        $type = match ($column->sqlType) {
            SchemaColumn::TYPE_INT => $column->unsigned
                ? new Union([new Type\Atomic\TIntRange(0, null)])
                : Type::getInt(),
            SchemaColumn::TYPE_STRING => Type::getString(),
            SchemaColumn::TYPE_FLOAT => Type::getFloat(),
            SchemaColumn::TYPE_BOOL => Type::getBool(),
            SchemaColumn::TYPE_ENUM => self::enumLiterals($column->options),
            SchemaColumn::TYPE_ARRAY => new Union([Type\Atomic\TKeyedArray::make(
                [Type::getFloat()],
                fallback_params: [Type::getInt(), Type::getFloat()],
                is_list: true,
            )]),
            default => Type::getMixed(),
        };

        if ($column->nullable) {
            $type = Type::combineUnionTypes($type, Type::getNull());
        }

        return $type;
    }

    /**
     * Emit a literal-string union from an ENUM column's allowed values.
     *
     * Empty options falls back to `string` — matches the pre-registry behavior.
     *
     * @param list<string> $options
     */
    private static function enumLiterals(array $options): Union
    {
        if ($options === []) {
            return Type::getString();
        }

        $literals = [];
        foreach ($options as $option) {
            $literals[] = Type\Atomic\TLiteralString::make($option);
        }

        return new Union($literals);
    }

    /**
     * Nested cache for {@see hasNativeProperty()}: `[$fqcn][$propertyName] => bool`.
     *
     * Nested rather than flat-keyed to avoid a `$fqcn . '::' . $propertyName` string
     * concat on every property access. Each access does two `isset()` probes instead.
     *
     * @var array<class-string, array<string, bool>>
     */
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
        // `array_key_exists` (not `isset`) — we cache `false` for properties that don't
        // exist, and `isset` would return false for the sentinel and re-run the probe.
        if (isset(self::$nativePropertyCache[$fqcn]) && \array_key_exists($propertyName, self::$nativePropertyCache[$fqcn])) {
            return self::$nativePropertyCache[$fqcn][$propertyName];
        }

        $result = \property_exists($fqcn, $propertyName);
        self::$nativePropertyCache[$fqcn][$propertyName] = $result;

        return $result;
    }
}
