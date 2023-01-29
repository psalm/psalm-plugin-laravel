<?php

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Psalm\Codebase;
use Psalm\LaravelPlugin\Providers\ModelStubProvider;
use Psalm\Plugin\EventHandler\Event\PropertyExistenceProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyVisibilityProviderEvent;
use Psalm\Plugin\EventHandler\PropertyExistenceProviderInterface;
use Psalm\Plugin\EventHandler\PropertyTypeProviderInterface;
use Psalm\Plugin\EventHandler\PropertyVisibilityProviderInterface;
use Psalm\Type;

use function str_replace;

final class ModelPropertyAccessorHandler implements PropertyExistenceProviderInterface, PropertyVisibilityProviderInterface, PropertyTypeProviderInterface
{
    /**
     * @return list<class-string<\Illuminate\Database\Eloquent\Model>>
     */
    public static function getClassLikeNames(): array
    {
        return ModelStubProvider::getModelClasses();
    }

    public static function doesPropertyExist(PropertyExistenceProviderEvent $event): ?bool
    {
        $source = $event->getSource();

        if (!$source || !$event->isReadMode()) {
            return null;
        }

        if (self::hasNativeProperty($event->getFqClasslikeName(), $event->getPropertyName())) {
            return true;
        }

        $codebase = $source->getCodebase();

        if (self::accessorExists($codebase, $event->getFqClasslikeName(), $event->getPropertyName())) {
            return true;
        }

        return null;
    }

    public static function isPropertyVisible(PropertyVisibilityProviderEvent $event): ?bool
    {
        if (!$event->isReadMode()) {
            return null;
        }

        if (self::hasNativeProperty($event->getFqClasslikeName(), $event->getPropertyName())) {
            return null;
        }

        $codebase = $event->getSource()->getCodebase();

        if (self::accessorExists($codebase, $event->getFqClasslikeName(), $event->getPropertyName())) {
            return true;
        }

        return null;
    }

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

        if (self::accessorExists($codebase, $fq_classlike_name, $property_name)) {
            return $codebase->getMethodReturnType($fq_classlike_name . '::get' . str_replace('_', '', $property_name) . 'Attribute', $fq_classlike_name)
                ?: Type::getMixed();
        }

        return null;
    }

    private static function hasNativeProperty(string $fqcn, string $property_name): bool
    {
        try {
            new \ReflectionProperty($fqcn, $property_name);
        } catch (\ReflectionException $exception) {
            return false;
        }

        return true;
    }

    private static function accessorExists(Codebase $codebase, string $fq_classlike_name, string $property_name): bool
    {
        return $codebase->methodExists($fq_classlike_name . '::get' . str_replace('_', '', $property_name) . 'Attribute');
    }
}
