<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Util\ModelPropertyResolver;
use Psalm\Plugin\EventHandler\Event\PropertyExistenceProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyVisibilityProviderEvent;
use Psalm\Type;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Union;

final class ModelRelationshipPropertyHandler
{
    /** @var array<string, bool> Cache for relationExists() keyed by "class::property" */
    private static array $relationExistsCache = [];

    /** @var array<string, ?Union> Return types fetched during relationExists(), reused by resolvePropertyType() */
    private static array $methodReturnTypeCache = [];

    /** @var array<string, Union> Cache for getPropertyType() keyed by "class::property" */
    private static array $propertyTypeCache = [];

    /** @var array<string, bool> Cache for hasUserPseudoProperty() keyed by "class::$property" */
    private static array $pseudoPropertyCache = [];

    /**
     * Relation classes that return a collection of models when accessed as a property.
     * All other Relation subclasses return a single nullable model (?TRelatedModel).
     */
    private const COLLECTION_RELATIONS = [
        BelongsToMany::class,
        HasMany::class,
        HasManyThrough::class,
        MorphMany::class,
        MorphToMany::class,
    ];

    public static function doesPropertyExist(PropertyExistenceProviderEvent $event): ?bool
    {
        $source = $event->getSource();

        if (!$source || !$event->isReadMode()) {
            return null;
        }

        $codebase = $source->getCodebase();
        $fq_classlike_name = $event->getFqClasslikeName();
        $property_name = $event->getPropertyName();

        if (self::hasUserPseudoProperty($codebase, $fq_classlike_name, $property_name)) {
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

        if (self::hasUserPseudoProperty($codebase, $fq_classlike_name, $property_name)) {
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

        if (self::hasUserPseudoProperty($codebase, $fq_classlike_name, $property_name)) {
            return null;
        }

        if (!self::relationExists($codebase, $fq_classlike_name, $property_name)) {
            return null;
        }

        $cacheKey = $fq_classlike_name . '::' . $property_name;

        if (\array_key_exists($cacheKey, self::$propertyTypeCache)) {
            return self::$propertyTypeCache[$cacheKey];
        }

        $result = self::resolvePropertyType($codebase, $fq_classlike_name, $property_name, $cacheKey);
        self::$propertyTypeCache[$cacheKey] = $result;

        return $result;
    }

    /**
     * Resolve the property type for a relationship accessor. Uses a three-tier strategy:
     *
     * 1. Extract from generic type parameters (when return type has explicit generics)
     * 2. Parse the method body AST to find the related model class-string argument
     * 3. Fall back to bounded types: ?Model for single relations, Collection<int, Model> for collection
     */
    private static function resolvePropertyType(
        Codebase $codebase,
        string $fq_classlike_name,
        string $property_name,
        string $methodId,
    ): Union {
        // Reuse the return type already fetched by relationExists() to avoid a redundant
        // getMethodReturnType() call (saves alias resolution + MethodIdentifier allocation).
        if (\array_key_exists($methodId, self::$methodReturnTypeCache)) {
            $methodReturnType = self::$methodReturnTypeCache[$methodId];
        } else {
            // Fallback: getMethodReturnType() takes $self_class by reference and may set it
            // to null, so use a disposable copy to protect $fq_classlike_name.
            $selfClass = $fq_classlike_name;
            try {
                $methodReturnType = $codebase->getMethodReturnType($methodId, $selfClass);
            } catch (\InvalidArgumentException $e) {
                $codebase->progress->debug("Laravel plugin: could not get return type for {$methodId}: {$e->getMessage()}\n");
                $methodReturnType = null;
            }
        }

        // Tier 1: Try to extract from generic type parameters (existing behavior).
        // Only possible when the return type is a Union with a TGenericObject.
        if ($methodReturnType instanceof Union) {
            $genericResult = self::resolveFromGenericParams($methodReturnType);
            if ($genericResult instanceof Union) {
                return $genericResult;
            }
        }

        // Tier 2: Parse the method body AST to find the related model class-string.
        // This handles both non-generic return types (e.g. plain BelongsTo) and methods
        // with no return type at all (e.g. public function image() { return $this->morphOne(...); }).
        $parsed = RelationMethodParser::parse($codebase, $fq_classlike_name, $property_name);
        if ($parsed !== null) {
            return self::buildPropertyType(
                $parsed['relationClass'],
                self::relatedModelType($parsed['relatedModel']),
            );
        }

        // Tier 3: Fall back using the declared relation class with bounded type (?Model / Collection<int, Model>).
        // This covers cases where the body couldn't be parsed but the return type is a known Relation.
        if ($methodReturnType instanceof Union) {
            $relationClassName = self::findRelationClassName($methodReturnType);
            if ($relationClassName !== null) {
                return self::buildPropertyType($relationClassName, self::relatedModelType(null));
            }
        }

        return Type::getMixed();
    }

    /**
     * Build a Union for the related model type, falling back to Model when unknown.
     *
     * @param ?string $relatedModel FQCN of the related model, or null when:
     *                              - the relation is polymorphic (morphTo)
     *                              - the first argument could not be statically resolved
     *
     * @psalm-pure
     */
    private static function relatedModelType(?string $relatedModel): Union
    {
        return new Union([new TNamedObject($relatedModel ?? Model::class)]);
    }

    /**
     * Tier 1: Extract the related model type from generic parameters on the return type.
     * Works when the method has explicit @psalm-return or @return with generics.
     *
     * e.g. HasOne<Phone, User> → extracts Phone as the model type
     *
     * @psalm-external-mutation-free
     */
    private static function resolveFromGenericParams(Union $methodReturnType): ?Union
    {
        foreach ($methodReturnType->getAtomicTypes() as $atomicType) {
            if (!$atomicType instanceof TGenericObject) {
                continue;
            }

            if (!\is_a($atomicType->value, Relation::class, true)) {
                continue;
            }

            // The first type_param is always TRelatedModel in our stubs.
            // Use it directly — it's a Union that may contain a named model type.
            $modelType = $atomicType->type_params[0] ?? null;
            if ($modelType instanceof Union && $modelType->hasObjectType()) {
                return self::buildPropertyType($atomicType->value, $modelType);
            }

            break;
        }

        return null;
    }

    /**
     * Find the Relation subclass name from a (possibly non-generic) return type.
     * Returns the FQCN of the first atomic type that is a Relation subclass, or null.
     *
     * @psalm-mutation-free
     */
    private static function findRelationClassName(Union $returnType): ?string
    {
        foreach ($returnType->getAtomicTypes() as $type) {
            if ($type instanceof TNamedObject && \is_a($type->value, Relation::class, true)) {
                return $type->value;
            }
        }

        return null;
    }

    /**
     * Build the property type based on the relation class and related model type.
     *
     * Single relations (HasOne, BelongsTo, MorphOne, MorphTo, HasOneThrough) → ?RelatedModel
     * Collection relations (HasMany, BelongsToMany, etc.) → Collection<int, RelatedModel>
     *   — uses the model's custom collection class when registered (e.g. #[CollectedBy])
     *
     * @psalm-external-mutation-free
     */
    private static function buildPropertyType(string $relationClassName, Union $modelType): Union
    {
        if (\in_array($relationClassName, self::COLLECTION_RELATIONS, true)) {
            // Check if the related model has a custom collection registered.
            // extractModelFromUnion() returns null for polymorphic/unresolved models,
            // in which case we fall back to the default Eloquent\Collection.
            $modelClass = ModelPropertyResolver::extractModelFromUnion($modelType);
            $collectionClass = $modelClass !== null
                ? (CustomCollectionHandler::getCollectionClassForModel($modelClass) ?? Collection::class)
                : Collection::class;

            return new Union([
                new TGenericObject($collectionClass, [
                    new Union([new TInt()]),
                    $modelType,
                ]),
            ]);
        }

        // Single relation — return nullable model type.
        // Build the union directly instead of using Type::combineUnionTypes() which
        // dispatches into the heavyweight TypeCombiner for this simple case.
        return $modelType->getBuilder()->addType(new TNull())->freeze();
    }

    /**
     * Check whether the user has declared a @property PHPDoc for this property.
     * If so, we defer to their declaration instead of providing a relationship type.
     *
     * @psalm-external-mutation-free
     */
    private static function hasUserPseudoProperty(Codebase $codebase, string $fq_classlike_name, string $property_name): bool
    {
        $key = $fq_classlike_name . '::$' . $property_name;

        if (\array_key_exists($key, self::$pseudoPropertyCache)) {
            return self::$pseudoPropertyCache[$key];
        }

        $classStorage = $codebase->classlike_storage_provider->get($fq_classlike_name);
        $result = isset($classStorage->pseudo_property_get_types['$' . $property_name]);
        self::$pseudoPropertyCache[$key] = $result;

        return $result;
    }

    /**
     * Check whether a method on the given class is a relationship method.
     *
     * Accepts both generic (BelongsTo<Vault, Contact>) and non-generic (BelongsTo)
     * return types, as long as the type is a Relation subclass.
     */
    private static function relationExists(Codebase $codebase, string $fq_classlike_name, string $property_name): bool
    {
        $key = $fq_classlike_name . '::' . $property_name;

        if (\array_key_exists($key, self::$relationExistsCache)) {
            return self::$relationExistsCache[$key];
        }

        if ($codebase->methodExists($key)) {
            // getMethodReturnType() takes $self_class by reference and may set it to null,
            // so use a disposable copy to protect $fq_classlike_name.
            $selfClass = $fq_classlike_name;
            try {
                $return_type = $codebase->getMethodReturnType($key, $selfClass);
            } catch (\InvalidArgumentException $e) {
                $codebase->progress->debug("Laravel plugin: could not get return type for {$key}: {$e->getMessage()}\n");
                $return_type = null;
            }

            // Cache the return type so resolvePropertyType() can reuse it without
            // calling getMethodReturnType() again (avoids redundant alias resolution,
            // class storage lookups, and MethodIdentifier allocation).
            self::$methodReturnTypeCache[$key] = $return_type;

            if ($return_type instanceof Union) {
                foreach ($return_type->getAtomicTypes() as $type) {
                    // Accept both TGenericObject (e.g. BelongsTo<Vault, Contact>) and
                    // TNamedObject (e.g. plain BelongsTo without generics) — both indicate
                    // a relationship method whose name maps to a magic property accessor.
                    if ($type instanceof TNamedObject && \is_a($type->value, Relation::class, true)) {
                        self::$relationExistsCache[$key] = true;
                        return true;
                    }
                }
            }

            // No return type declared — check method body for relationship factory calls.
            // This handles cases like: public function image() { return $this->morphOne(...); }
            if (!$return_type instanceof Union && RelationMethodParser::parse($codebase, $fq_classlike_name, $property_name) !== null) {
                self::$relationExistsCache[$key] = true;
                return true;
            }
        }

        self::$relationExistsCache[$key] = false;
        return false;
    }
}
