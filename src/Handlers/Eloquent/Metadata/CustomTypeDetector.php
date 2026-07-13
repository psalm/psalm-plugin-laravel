<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Metadata;

use Illuminate\Database\Eloquent\Attributes\CollectedBy;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Psalm\Codebase;

/**
 * Pure reflection-based detection of a model's custom Eloquent Builder and Collection classes.
 *
 * Scan-time metadata computation with no Psalm hook concern: it reads a model's
 * `newEloquentBuilder()` / `newCollection()` overrides, `#[UseEloquentBuilder]` / `#[CollectedBy]`
 * attributes, and static `$builder` / `$collectionClass` properties, mirroring Laravel's own
 * resolution priority. Both {@see ModelMetadataRegistryBuilder} (warm-up) and
 * {@see \Psalm\LaravelPlugin\Handlers\Eloquent\ModelRegistrationHandler} (warm-up-failure fallback)
 * depend down onto this class, so neither the data layer nor the hook layer reaches into the other.
 *
 * @internal
 */
final class CustomTypeDetector
{
    /**
     * Pure detection of a model's custom Eloquent builder, matching Laravel's resolution priority:
     * 1. newEloquentBuilder() override — bypasses the attribute and property when present.
     * 2. #[UseEloquentBuilder] attribute — checked first in the base newEloquentBuilder().
     * 3. protected static string $builder property — fallback in the base newEloquentBuilder().
     *
     * Reflection-only (abstract bases included). Single detection path: warmUp() calls it to populate
     * ModelMetadata::$customBuilder; registerHandlersForModel() reads that value and owns the
     * registerCustomBuilder() side effect (the old detectCustomBuilder() wrapper is gone — Gotcha 8).
     *
     * @param class-string<Model> $className
     * @return class-string<Builder>|null The custom builder class, or null if using base Builder.
     */
    public static function resolveCustomBuilderClass(
        Codebase $codebase,
        string $className,
        bool $failOnError = false,
    ): ?string {
        try {
            $reflection = new \ReflectionClass($className);
        } catch (\ReflectionException $reflectionException) {
            if ($failOnError) {
                throw $reflectionException;
            }

            $codebase->progress->debug(
                "Laravel plugin: could not reflect model '{$className}' for custom builder detection: {$reflectionException->getMessage()}\n",
            );

            return null;
        }

        // 1. newEloquentBuilder() override — bypasses attribute and property when present.
        $builderClass = self::resolveBuilderFromMethodOverride($reflection);

        // 2. #[UseEloquentBuilder] attribute — checked first in the base newEloquentBuilder().
        if ($builderClass === null) {
            $builderClass = self::resolveBuilderFromAttribute($reflection, $codebase, $failOnError);
        }

        // 3. Fall back to static $builder property.
        if ($builderClass === null) {
            $builderClass = self::resolveBuilderFromStaticProperty($reflection);
        }

        if ($builderClass === null) {
            return null;
        }

        // is_subclass_of() may trigger autoloading which can throw for broken classes.
        try {
            $isValid = \is_subclass_of($builderClass, Builder::class, true);
        } catch (\Error|\Exception $error) {
            if ($failOnError) {
                throw $error;
            }

            $codebase->progress->debug(
                "Laravel plugin: model '{$className}' builder '{$builderClass}' failed autoloading: {$error->getMessage()}\n",
            );

            return null;
        }

        if ($isValid) {
            /** @var class-string<Builder> $builderClass */
            return $builderClass;
        }

        $codebase->progress->debug(
            "Laravel plugin: model '{$className}' declares custom builder '{$builderClass}' "
            . 'but it does not extend '
            . Builder::class
            . " — ignoring\n",
        );

        return null;
    }

    /**
     * Resolve custom builder from #[UseEloquentBuilder] attribute.
     *
     * @return class-string|null
     */
    private static function resolveBuilderFromAttribute(
        \ReflectionClass $reflection,
        Codebase $codebase,
        bool $failOnError = false,
    ): ?string {
        if (!\class_exists(UseEloquentBuilder::class)) {
            return null;
        }

        $attributes = $reflection->getAttributes(UseEloquentBuilder::class);
        if ($attributes === []) {
            return null;
        }

        try {
            return $attributes[0]->newInstance()->builderClass;
        } catch (\Error $error) {
            if ($failOnError) {
                throw $error;
            }

            $codebase->progress->debug(
                "Laravel plugin: #[UseEloquentBuilder] on '{$reflection->getName()}' failed to instantiate: {$error->getMessage()}\n",
            );

            return null;
        }
    }

    /**
     * Resolve custom builder from a newEloquentBuilder() override with a native return type.
     *
     * Detects any override whose declaring class is not Illuminate\Database\Eloquent\Model
     * (including overrides in an intermediate base model) with a PHP native return type.
     *
     * @return class-string|null
     * @psalm-mutation-free
     */
    private static function resolveBuilderFromMethodOverride(\ReflectionClass $reflection): ?string
    {
        try {
            $method = $reflection->getMethod('newEloquentBuilder');
        } catch (\ReflectionException) {
            return null;
        }

        // Only consider overrides declared on the model, not the base Model method.
        if ($method->getDeclaringClass()->getName() === Model::class) {
            return null;
        }

        $returnType = $method->getReturnType();
        if (!$returnType instanceof \ReflectionNamedType || $returnType->isBuiltin()) {
            return null;
        }

        return $returnType->getName();
    }

    /**
     * Resolve custom builder from a static $builder property override (all Laravel versions).
     *
     * Detects when a model overrides the protected static string $builder property
     * with a custom builder class name.
     *
     * @return class-string|null
     */
    private static function resolveBuilderFromStaticProperty(\ReflectionClass $reflection): ?string
    {
        try {
            $property = $reflection->getProperty('builder');
        } catch (\ReflectionException) {
            return null;
        }

        // Only consider overrides, not the base Model::$builder = Builder::class.
        if ($property->getDeclaringClass()->getName() === Model::class) {
            return null;
        }

        /** @psalm-var class-string|null $value */
        $value = $property->getValue();

        return $value;
    }

    /**
     * Pure detection of a model's custom Eloquent collection, matching Laravel's resolution priority
     * in HasCollection::newCollection():
     * 1. newCollection() override — if the model overrides this method, it bypasses the attribute and
     *    property checks entirely (Laravel calls the override directly)
     * 2. #[CollectedBy] attribute — checked first inside the base newCollection()
     * 3. protected static string $collectionClass property — fallback in the base newCollection()
     *
     * Reflection-only (abstract bases included). Single detection path, mirroring
     * {@see resolveCustomBuilderClass}: warmUp() populates ModelMetadata::$customCollection;
     * registerHandlersForModel() reads it and owns the registerCustomCollection() side effect (the old
     * detectCustomCollection() wrapper is gone — Gotcha 8).
     *
     * @param class-string<Model> $className
     * @return class-string<EloquentCollection>|null
     */
    public static function resolveCustomCollectionClass(
        Codebase $codebase,
        string $className,
        bool $failOnError = false,
    ): ?string {
        try {
            $reflection = new \ReflectionClass($className);
        } catch (\ReflectionException $reflectionException) {
            if ($failOnError) {
                throw $reflectionException;
            }

            $codebase->progress->debug(
                "Laravel plugin: could not reflect model '{$className}' for custom collection detection: {$reflectionException->getMessage()}\n",
            );

            return null;
        }

        // 1. newCollection() override — bypasses attribute and property when present.
        $collectionClass = self::resolveCollectionFromMethodOverride($reflection);

        // 2. #[CollectedBy] attribute — checked first in the base newCollection().
        if ($collectionClass === null) {
            $collectionClass = self::resolveCollectionFromAttribute($reflection, $codebase, $failOnError);
        }

        // 3. Fall back to static $collectionClass property.
        if ($collectionClass === null) {
            $collectionClass = self::resolveCollectionFromStaticProperty($reflection);
        }

        if ($collectionClass === null) {
            return null;
        }

        // Validate that the class is a Collection subclass.
        // is_subclass_of() may trigger autoloading which can throw for broken classes.
        try {
            $isValid = \is_subclass_of($collectionClass, EloquentCollection::class, true);
        } catch (\Error|\Exception $error) {
            if ($failOnError) {
                throw $error;
            }

            $codebase->progress->debug(
                "Laravel plugin: model '{$className}' collection '{$collectionClass}' failed autoloading: {$error->getMessage()}\n",
            );

            return null;
        }

        if ($isValid) {
            /** @var class-string<EloquentCollection> $collectionClass */
            return $collectionClass;
        }

        $codebase->progress->debug(
            "Laravel plugin: model '{$className}' declares custom collection '{$collectionClass}' "
            . 'but it does not extend '
            . EloquentCollection::class
            . " — ignoring\n",
        );

        return null;
    }

    /**
     * Resolve custom collection from #[CollectedBy] attribute.
     *
     * Walks up the parent class chain for grandchild models, matching Laravel's
     * HasCollection::resolveCollectionFromAttribute() behavior: if a base model
     * declares #[CollectedBy], child models inherit the custom collection.
     *
     * @return class-string|null
     */
    private static function resolveCollectionFromAttribute(
        \ReflectionClass $reflection,
        Codebase $codebase,
        bool $failOnError = false,
    ): ?string {
        if (!\class_exists(CollectedBy::class)) {
            return null;
        }

        $attributes = $reflection->getAttributes(CollectedBy::class);
        if ($attributes !== []) {
            try {
                /** @psalm-var class-string */
                return $attributes[0]->newInstance()->collectionClass;
            } catch (\Error $error) {
                if ($failOnError) {
                    throw $error;
                }

                $codebase->progress->debug(
                    "Laravel plugin: #[CollectedBy] on '{$reflection->getName()}' failed to instantiate: {$error->getMessage()}\n",
                );

                return null;
            }
        }

        // Walk up to parent model (grandchild inheritance), matching Laravel's behavior.
        // A model whose direct parent is Model itself has no intermediate base to inherit from.
        $parentClass = $reflection->getParentClass();
        if (
            $parentClass !== false
            && $parentClass->getName() !== Model::class
            && $parentClass->isSubclassOf(Model::class)
        ) {
            return self::resolveCollectionFromAttribute($parentClass, $codebase, $failOnError);
        }

        return null;
    }

    /**
     * Resolve custom collection from a newCollection() override with a native return type.
     *
     * Detects any override whose declaring class is not Illuminate\Database\Eloquent\Model
     * (including overrides in an intermediate base model) with a PHP native return type
     * that is a concrete class (not EloquentCollection itself).
     *
     * @return class-string|null
     * @psalm-mutation-free
     */
    private static function resolveCollectionFromMethodOverride(\ReflectionClass $reflection): ?string
    {
        try {
            $method = $reflection->getMethod('newCollection');
        } catch (\ReflectionException) {
            return null;
        }

        // Only consider overrides declared on the model, not the base Model method.
        if ($method->getDeclaringClass()->getName() === Model::class) {
            return null;
        }

        $returnType = $method->getReturnType();
        if (!$returnType instanceof \ReflectionNamedType || $returnType->isBuiltin()) {
            return null;
        }

        $typeName = $returnType->getName();

        // Skip if it just returns the base EloquentCollection — not a custom collection.
        if ($typeName === EloquentCollection::class) {
            return null;
        }

        return $typeName;
    }

    /**
     * Resolve custom collection from a static $collectionClass property override (all Laravel versions).
     *
     * Detects when a model overrides the protected static string $collectionClass property
     * with a custom collection class name.
     *
     * @return class-string|null
     */
    private static function resolveCollectionFromStaticProperty(\ReflectionClass $reflection): ?string
    {
        try {
            $property = $reflection->getProperty('collectionClass');
        } catch (\ReflectionException) {
            return null;
        }

        // Only consider overrides, not the base Model::$collectionClass = Collection::class.
        if ($property->getDeclaringClass()->getName() === Model::class) {
            return null;
        }

        /** @psalm-var class-string|null $value */
        $value = $property->getValue();

        return $value;
    }
}
