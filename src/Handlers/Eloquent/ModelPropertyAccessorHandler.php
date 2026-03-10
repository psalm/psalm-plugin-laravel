<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Providers\ModelDiscoveryProvider;
use Psalm\Plugin\EventHandler\Event\PropertyExistenceProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyVisibilityProviderEvent;
use Psalm\Plugin\EventHandler\PropertyExistenceProviderInterface;
use Psalm\Plugin\EventHandler\PropertyTypeProviderInterface;
use Psalm\Plugin\EventHandler\PropertyVisibilityProviderInterface;
use Psalm\Type;

use function array_key_exists;
use function is_a;
use function lcfirst;
use function property_exists;
use function str_replace;
use function ucwords;

final class ModelPropertyAccessorHandler implements PropertyExistenceProviderInterface, PropertyVisibilityProviderInterface, PropertyTypeProviderInterface
{
    /** @var array<string, bool> Cache for hasNativeProperty() keyed by "class::property" */
    private static array $nativePropertyCache = [];

    /** @var array<string, bool> Cache for legacyAccessorExists() keyed by "class::property" */
    private static array $legacyAccessorCache = [];

    /** @var array<string, bool> Cache for newStyleAccessorExists() keyed by "class::property" */
    private static array $newStyleAccessorCache = [];

    /** @var array<string, Type\Union> Cache for getNewStyleAccessorType() keyed by "class::property" */
    private static array $accessorTypeCache = [];

    /**
     * @return list<string>
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return ModelDiscoveryProvider::getModelClasses();
    }

    /** @inheritDoc */
    #[\Override]
    public static function doesPropertyExist(PropertyExistenceProviderEvent $event): ?bool
    {
        $source = $event->getSource();

        if (!$source || !$event->isReadMode()) {
            return null;
        }

        if (self::hasNativeProperty($event->getFqClasslikeName(), $event->getPropertyName())) {
            return null;
        }

        $codebase = $source->getCodebase();

        if (self::legacyAccessorExists($codebase, $event->getFqClasslikeName(), $event->getPropertyName())) {
            return true;
        }

        if (self::newStyleAccessorExists($codebase, $event->getFqClasslikeName(), $event->getPropertyName())) {
            return true;
        }

        return null;
    }

    #[\Override]
    public static function isPropertyVisible(PropertyVisibilityProviderEvent $event): ?bool
    {
        if (!$event->isReadMode()) {
            return null;
        }

        if (self::hasNativeProperty($event->getFqClasslikeName(), $event->getPropertyName())) {
            return null;
        }

        $codebase = $event->getSource()->getCodebase();

        if (self::legacyAccessorExists($codebase, $event->getFqClasslikeName(), $event->getPropertyName())) {
            return true;
        }

        if (self::newStyleAccessorExists($codebase, $event->getFqClasslikeName(), $event->getPropertyName())) {
            return true;
        }

        return null;
    }

    #[\Override]
    public static function getPropertyType(PropertyTypeProviderEvent $event): ?Type\Union
    {
        $source = $event->getSource();

        if (!$source || !$event->isReadMode()) {
            return null;
        }

        // skip for real properties like $hidden, $casts
        if (self::hasNativeProperty($event->getFqClasslikeName(), $event->getPropertyName())) {
            return null;
        }

        $codebase = $source->getCodebase();
        $fq_classlike_name = $event->getFqClasslikeName();
        $property_name = $event->getPropertyName();

        // Check new-style Attribute accessor first (takes priority)
        if (self::newStyleAccessorExists($codebase, $fq_classlike_name, $property_name)) {
            return self::getNewStyleAccessorType($codebase, $fq_classlike_name, $property_name);
        }

        // Fall back to legacy getXxxAttribute accessor
        if (self::legacyAccessorExists($codebase, $fq_classlike_name, $property_name)) {
            $attributeGetterName = 'get' . str_replace('_', '', $property_name) . 'Attribute';
            return $codebase->getMethodReturnType("{$fq_classlike_name}::{$attributeGetterName}", $fq_classlike_name)
                ?: Type::getMixed();
        }

        return null;
    }

    /** @psalm-external-mutation-free */
    private static function hasNativeProperty(string $fqcn, string $property_name): bool
    {
        $key = $fqcn . '::' . $property_name;

        if (array_key_exists($key, self::$nativePropertyCache)) {
            return self::$nativePropertyCache[$key];
        }

        $result = property_exists($fqcn, $property_name);
        self::$nativePropertyCache[$key] = $result;

        return $result;
    }

    private static function legacyAccessorExists(Codebase $codebase, string $fq_classlike_name, string $property_name): bool
    {
        $key = $fq_classlike_name . '::' . $property_name;

        if (array_key_exists($key, self::$legacyAccessorCache)) {
            return self::$legacyAccessorCache[$key];
        }

        $result = $codebase->methodExists($fq_classlike_name . '::get' . str_replace('_', '', $property_name) . 'Attribute');
        self::$legacyAccessorCache[$key] = $result;

        return $result;
    }

    /**
     * Check for new-style Attribute accessor: a method named in camelCase that returns Attribute.
     *
     * For property 'first_name', checks for method 'firstName()' returning Attribute<TGet, TSet>.
     */
    private static function newStyleAccessorExists(Codebase $codebase, string $fq_classlike_name, string $property_name): bool
    {
        $key = $fq_classlike_name . '::' . $property_name;

        if (array_key_exists($key, self::$newStyleAccessorCache)) {
            return self::$newStyleAccessorCache[$key];
        }

        $methodName = self::propertyToCamelCase($property_name);
        $method = $fq_classlike_name . '::' . $methodName;

        if ($codebase->methodExists($method)) {
            $returnType = $codebase->getMethodReturnType($method, $fq_classlike_name);
            if ($returnType !== null) {
                foreach ($returnType->getAtomicTypes() as $type) {
                    // TGenericObject extends TNamedObject, so this catches both
                    if ($type instanceof Type\Atomic\TNamedObject && is_a($type->value, Attribute::class, true)) {
                        self::$newStyleAccessorCache[$key] = true;
                        return true;
                    }
                }
            }
        }

        self::$newStyleAccessorCache[$key] = false;
        return false;
    }

    /**
     * Extract TGet from the Attribute<TGet, TSet> return type of a new-style accessor.
     */
    private static function getNewStyleAccessorType(Codebase $codebase, string $fq_classlike_name, string $property_name): Type\Union
    {
        $key = $fq_classlike_name . '::' . $property_name;

        if (array_key_exists($key, self::$accessorTypeCache)) {
            return self::$accessorTypeCache[$key];
        }

        $methodName = self::propertyToCamelCase($property_name);
        $method = $fq_classlike_name . '::' . $methodName;

        $returnType = $codebase->getMethodReturnType($method, $fq_classlike_name);
        if ($returnType === null) {
            $result = Type::getMixed();
            self::$accessorTypeCache[$key] = $result;
            return $result;
        }

        foreach ($returnType->getAtomicTypes() as $type) {
            if ($type instanceof Type\Atomic\TGenericObject && is_a($type->value, Attribute::class, true)) {
                // TGet is the first template parameter
                if (isset($type->type_params[0])) {
                    self::$accessorTypeCache[$key] = $type->type_params[0];
                    return $type->type_params[0];
                }
            }
        }

        $result = Type::getMixed();
        self::$accessorTypeCache[$key] = $result;
        return $result;
    }

    /**
     * Convert snake_case property name to camelCase method name.
     *
     * 'first_name' → 'firstName'
     * 'email_verified_at' → 'emailVerifiedAt'
     *
     * @psalm-pure
     */
    private static function propertyToCamelCase(string $property_name): string
    {
        return lcfirst(str_replace('_', '', ucwords($property_name, '_')));
    }
}
