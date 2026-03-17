<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Psalm\Codebase;
use Psalm\Plugin\EventHandler\Event\PropertyExistenceProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyVisibilityProviderEvent;
use Psalm\Type;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Union;

final class ModelRelationshipPropertyHandler
{
    /** @var array<string, bool> Cache for relationExists() keyed by "class::property" */
    private static array $relationExistsCache = [];

    /** @var array<string, Union> Cache for getPropertyType() keyed by "class::property" */
    private static array $propertyTypeCache = [];

    public static function doesPropertyExist(PropertyExistenceProviderEvent $event): ?bool
    {
        $source = $event->getSource();

        if (!$source || !$event->isReadMode()) {
            return null;
        }

        $codebase = $source->getCodebase();
        $fq_classlike_name = $event->getFqClasslikeName();
        $property_name = $event->getPropertyName();

        // Defer to user @property PHPDoc
        $classStorage = $codebase->classlike_storage_provider->get($fq_classlike_name);
        if (isset($classStorage->pseudo_property_get_types['$' . $property_name])) {
            return null;
        }

        if (self::relationExists($codebase, $fq_classlike_name, $property_name)) {
            return true;
        }

        return null;
    }

    public static function isPropertyVisible(PropertyVisibilityProviderEvent $event): ?bool
    {
        if (!$event->isReadMode()) {
            return null;
        }

        $codebase = $event->getSource()->getCodebase();
        $fq_classlike_name = $event->getFqClasslikeName();
        $property_name = $event->getPropertyName();

        // Defer to user @property PHPDoc
        $classStorage = $codebase->classlike_storage_provider->get($fq_classlike_name);
        if (isset($classStorage->pseudo_property_get_types['$' . $property_name])) {
            return null;
        }

        if (self::relationExists($codebase, $fq_classlike_name, $property_name)) {
            return true;
        }

        return null;
    }

    public static function getPropertyType(PropertyTypeProviderEvent $event): ?Union
    {
        $source = $event->getSource();

        if (!$source || !$event->isReadMode()) {
            return null;
        }

        $codebase = $source->getCodebase();
        $fq_classlike_name = $event->getFqClasslikeName();
        $property_name = $event->getPropertyName();

        // Defer to user @property PHPDoc
        $classStorage = $codebase->classlike_storage_provider->get($fq_classlike_name);
        if (isset($classStorage->pseudo_property_get_types['$' . $property_name])) {
            return null;
        }

        if (!self::relationExists($codebase, $fq_classlike_name, $property_name)) {
            return null;
        }

        $cacheKey = $fq_classlike_name . '::' . $property_name;

        if (\array_key_exists($cacheKey, self::$propertyTypeCache)) {
            return self::$propertyTypeCache[$cacheKey];
        }

        $methodReturnType = $codebase->getMethodReturnType($cacheKey, $fq_classlike_name);
        if (!$methodReturnType instanceof Union) {
            $result = Type::getMixed();
            self::$propertyTypeCache[$cacheKey] = $result;
            return $result;
        }

        /** @var Union|null $modelType */
        $modelType = null;
        /** @var TGenericObject|null $relationType */
        $relationType = null;

        foreach ($methodReturnType->getAtomicTypes() as $atomicType) {
            if (!$atomicType instanceof TGenericObject) {
                continue;
            }

            $relationType = $atomicType;

            foreach ($atomicType->type_params as $childNode) {
                foreach ($childNode->getAtomicTypes() as $childAtomicType) {
                    if (!$childAtomicType instanceof Type\Atomic\TNamedObject) {
                        continue;
                    }

                    $modelType = $childNode;
                    break 3;
                }
            }
        }

        $returnType = $modelType;

        $relationsThatReturnACollection = [
            BelongsToMany::class,
            HasMany::class,
            HasManyThrough::class,
            MorphMany::class,
            MorphToMany::class,
        ];

        if ($modelType && $relationType && \in_array($relationType->value, $relationsThatReturnACollection, true)) {
            $returnType = new Union([
                new TGenericObject(Collection::class, [
                    new Union([new TInt()]),
                    $modelType,
                ]),
            ]);
        }

        $result = $returnType ?: Type::getMixed();
        self::$propertyTypeCache[$cacheKey] = $result;

        return $result;
    }

    private static function relationExists(Codebase $codebase, string $fq_classlike_name, string $property_name): bool
    {
        $key = $fq_classlike_name . '::' . $property_name;

        if (\array_key_exists($key, self::$relationExistsCache)) {
            return self::$relationExistsCache[$key];
        }

        if ($codebase->methodExists($key)) {
            $return_type = $codebase->getMethodReturnType($key, $fq_classlike_name);
            if ($return_type instanceof Union) {
                foreach ($return_type->getAtomicTypes() as $type) {
                    if ($type instanceof TGenericObject && \is_a($type->value, Relation::class, true)) {
                        self::$relationExistsCache[$key] = true;
                        return true;
                    }
                }
            }
        }

        self::$relationExistsCache[$key] = false;
        return false;
    }
}
