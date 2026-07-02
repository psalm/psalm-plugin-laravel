<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\AccessorInfo;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistry;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\EloquentModelMethods;
use Psalm\Plugin\EventHandler\Event\PropertyExistenceProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyVisibilityProviderEvent;
use Psalm\Type;

/**
 * Resolves READ access to Eloquent accessor properties — legacy `getXxxAttribute()` and
 * `Attribute::make()`-style — from the pre-computed accessor map on {@see ModelMetadataRegistry}.
 *
 * The registry map is full-callable (an accessor's declaring class may be the model, a trait, or
 * any inherited user ancestor — matching `Codebase::methodExists()`'s inheritance-aware resolution)
 * and is warmed once during `AfterCodebasePopulated`. So this handler no longer scans the codebase
 * per property access; it looks up the snake_case property key. Attribute-style accessors win over
 * legacy for the same key (the map bakes that precedence in), preserving the prior "new-style first"
 * type resolution.
 *
 * Read-mode only. Write access (legacy `setXxxAttribute`, `Attribute::make(set:)`) is registered as
 * `pseudo_property_set_types` by {@see ModelRegistrationHandler}; that path is untouched here.
 *
 * @internal
 */
final class ModelPropertyAccessorHandler
{
    /** @var array<string, bool> Cache for hasNativeProperty() keyed by "class::property". */
    private static array $nativePropertyCache = [];

    public static function doesPropertyExist(PropertyExistenceProviderEvent $event): ?bool
    {
        $source = $event->getSource();

        if (!$source || !$event->isReadMode()) {
            return null;
        }

        // Registered per concrete/abstract Model subclass, so the receiver FQCN is a model class-string
        // at runtime — same narrowing ModelPropertyHandler applies for its registry reads.
        /** @var class-string<Model> $fqcn */
        $fqcn = $event->getFqClasslikeName();

        return self::resolveAccessor($source->getCodebase(), $fqcn, $event->getPropertyName()) instanceof AccessorInfo
            ? true
            : null;
    }

    public static function isPropertyVisible(PropertyVisibilityProviderEvent $event): ?bool
    {
        if (!$event->isReadMode()) {
            return null;
        }

        /** @var class-string<Model> $fqcn */
        $fqcn = $event->getFqClasslikeName();

        return self::resolveAccessor($event->getSource()->getCodebase(), $fqcn, $event->getPropertyName())
            instanceof AccessorInfo
            ? true
            : null;
    }

    public static function getPropertyType(PropertyTypeProviderEvent $event): ?Type\Union
    {
        $source = $event->getSource();

        if (!$source || !$event->isReadMode()) {
            return null;
        }

        /** @var class-string<Model> $fqcn */
        $fqcn = $event->getFqClasslikeName();

        return self::resolveAccessor($source->getCodebase(), $fqcn, $event->getPropertyName())?->returnType;
    }

    /**
     * Look up the winning accessor for a property, honoring the two pre-registry deferral guards:
     * a real declared PHP property (e.g. `$casts`) and a user `@property` PHPDoc both defer to Psalm.
     *
     * The property name is normalized through the SAME key the builder keys accessors by
     * ({@see EloquentModelMethods::accessorPropertyKey()} — separators stripped, lowercased), so a
     * `firstName(): Attribute` accessor resolves via `$model->first_name`, `$model->firstName`,
     * `$model->fullname`, and an acronym `apiURL()` via `$model->api_url` alike — reproducing Laravel's
     * separator-collapsing, case-insensitive `Str::camel` / `Str::studly` resolution (and the
     * pre-registry handler's own `str_replace('_', '')` + case-insensitive `methodExists` matching).
     *
     * @param class-string<Model> $fqcn
     * @psalm-external-mutation-free
     */
    private static function resolveAccessor(Codebase $codebase, string $fqcn, string $propertyName): ?AccessorInfo
    {
        if (self::hasNativeProperty($fqcn, $propertyName)) {
            return null;
        }

        // Defer to user @property PHPDoc.
        $classStorage = $codebase->classlike_storage_provider->get($fqcn);
        if (isset($classStorage->pseudo_property_get_types['$' . $propertyName])) {
            return null;
        }

        $key = EloquentModelMethods::accessorPropertyKey($propertyName);
        if ($key === null) {
            return null;
        }

        $metadata = ModelMetadataRegistry::for($fqcn);
        if (!$metadata instanceof ModelMetadata) {
            return null;
        }

        return $metadata->accessors()[$key] ?? null;
    }

    /** @psalm-external-mutation-free */
    private static function hasNativeProperty(string $fqcn, string $property_name): bool
    {
        $key = $fqcn . '::' . $property_name;

        if (\array_key_exists($key, self::$nativePropertyCache)) {
            return self::$nativePropertyCache[$key];
        }

        return self::$nativePropertyCache[$key] = \property_exists($fqcn, $property_name);
    }
}
