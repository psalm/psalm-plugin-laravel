<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\CastResolver;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\CastsMethodParser;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn;
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
    /** @var array<string, string> model class → table name cache */
    private static array $tableNameCache = [];

    /** @var array<string, array<string, string>> model class → merged casts cache */
    private static array $castsCache = [];

    /**
     * @psalm-suppress PossiblyUnusedMethod called externally by test infrastructure
     * @psalm-suppress MissingPureAnnotation mutates static caches intentionally
     */
    public static function reset(): void
    {
        self::$tableNameCache = [];
        self::$castsCache = [];
    }

    public static function doesPropertyExist(PropertyExistenceProviderEvent $event): ?bool
    {
        if (!$event->isReadMode()) {
            return null;
        }

        $source = $event->getSource();
        if (!$source instanceof \Psalm\StatementsSource) {
            return null;
        }

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

        $column = self::resolveColumn($fqClasslikeName, $propertyName);
        if ($column instanceof \Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn) {
            return true;
        }

        return null;
    }

    public static function isPropertyVisible(PropertyVisibilityProviderEvent $event): ?bool
    {
        if (!$event->isReadMode()) {
            return null;
        }

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

        $column = self::resolveColumn($fqClasslikeName, $propertyName);
        if ($column instanceof \Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn) {
            return true;
        }

        return null;
    }

    public static function getPropertyType(PropertyTypeProviderEvent $event): ?Union
    {
        $source = $event->getSource();
        if (!$source instanceof \Psalm\StatementsSource || !$event->isReadMode()) {
            return null;
        }

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

        $column = self::resolveColumn($fqClasslikeName, $propertyName);
        if (!$column instanceof \Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn) {
            return null;
        }

        // Check if there's a cast override
        $casts = self::resolveCasts($codebase, $fqClasslikeName);
        if (isset($casts[$propertyName])) {
            return CastResolver::resolve($casts[$propertyName], $column->nullable);
        }

        // Map schema column type to Psalm type
        return self::mapColumnType($column);
    }

    private static function resolveColumn(string $fqClasslikeName, string $propertyName): ?SchemaColumn
    {
        $schema = SchemaStateProvider::getSchema();
        if (!$schema instanceof \Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator) {
            return null;
        }

        $tableName = self::resolveTableName($fqClasslikeName);
        if ($tableName === null) {
            return null;
        }

        if (!isset($schema->tables[$tableName])) {
            return null;
        }

        return $schema->tables[$tableName]->columns[$propertyName] ?? null;
    }

    private static function resolveTableName(string $fqClasslikeName): ?string
    {
        if (isset(self::$tableNameCache[$fqClasslikeName])) {
            return self::$tableNameCache[$fqClasslikeName];
        }

        if (!\is_a($fqClasslikeName, Model::class, true)) {
            return null;
        }

        try {
            $reflection = new \ReflectionClass($fqClasslikeName);
            $instance = $reflection->newInstanceWithoutConstructor();

            if (!$instance instanceof Model) {
                return null;
            }

            $tableName = $instance->getTable();
        } catch (\ReflectionException) {
            return null;
        }

        self::$tableNameCache[$fqClasslikeName] = $tableName;

        return $tableName;
    }

    /**
     * @return array<string, string>
     */
    private static function resolveCasts(\Psalm\Codebase $codebase, string $fqClasslikeName): array
    {
        if (isset(self::$castsCache[$fqClasslikeName])) {
            return self::$castsCache[$fqClasslikeName];
        }

        $casts = [];

        // 1. SoftDeletes trait → deleted_at: datetime (lowest priority)
        $classStorage = $codebase->classlike_storage_provider->get($fqClasslikeName);
        if (isset($classStorage->used_traits[\strtolower(SoftDeletes::class)])) {
            $casts['deleted_at'] = 'datetime';
        }

        // 2. $casts property from the model instance
        if (\is_a($fqClasslikeName, Model::class, true)) {
            try {
                $reflection = new \ReflectionClass($fqClasslikeName);
                $instance = $reflection->newInstanceWithoutConstructor();

                /** @var array<string, string> $instanceCasts */
                $instanceCasts = $instance->getCasts();
                $casts = \array_merge($casts, $instanceCasts);
            } catch (\ReflectionException) {
                // Can't instantiate model — skip instance casts
            }
        }

        // 3. casts() method (AST-parsed, highest priority)
        $methodCasts = CastsMethodParser::parse($codebase, $fqClasslikeName);
        $casts = \array_merge($casts, $methodCasts);

        self::$castsCache[$fqClasslikeName] = $casts;

        return $casts;
    }

    private static function mapColumnType(SchemaColumn $column): Union
    {
        $type = match ($column->type) {
            SchemaColumn::TYPE_INT => $column->unsigned
                ? new Union([new Type\Atomic\TIntRange(0, null)])
                : Type::getInt(),
            SchemaColumn::TYPE_STRING => Type::getString(),
            SchemaColumn::TYPE_FLOAT => Type::getFloat(),
            SchemaColumn::TYPE_BOOL => Type::getBool(),
            SchemaColumn::TYPE_ENUM => self::mapEnumColumn($column),
            default => Type::getMixed(),
        };

        if ($column->nullable) {
            $type = Type::combineUnionTypes($type, Type::getNull());
        }

        return $type;
    }

    private static function mapEnumColumn(SchemaColumn $column): Union
    {
        if ($column->options === []) {
            return Type::getString();
        }

        $literals = [];
        foreach ($column->options as $option) {
            $literals[] = Type\Atomic\TLiteralString::make($option);
        }

        return new Union($literals);
    }

    private static function hasNativeProperty(string $fqcn, string $propertyName): bool
    {
        try {
            new \ReflectionProperty($fqcn, $propertyName);
        } catch (\ReflectionException) {
            return false;
        }

        return true;
    }
}
