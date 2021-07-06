<?php

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\LaravelPlugin\Providers\ModelStubProvider;
use Psalm\Plugin\Hook\PropertyExistenceProviderInterface;
use Psalm\Plugin\Hook\PropertyTypeProviderInterface;
use Psalm\Plugin\Hook\PropertyVisibilityProviderInterface;
use Psalm\StatementsSource;
use Psalm\Type;
use function str_replace;

final class ModelPropertyAccessorHandler implements PropertyExistenceProviderInterface, PropertyVisibilityProviderInterface, PropertyTypeProviderInterface
{
    /**
     * @return array<string>
     */
    public static function getClassLikeNames(): array
    {
        return ModelStubProvider::getModelClasses();
    }


    public static function doesPropertyExist(string $fq_classlike_name, string $property_name, bool $read_mode, ?StatementsSource $source = null, ?Context $context = null, ?CodeLocation $code_location = null): ?bool
    {
        if (!$source || !$read_mode) {
            return null;
        }

        $codebase = $source->getCodebase();

        if (self::accessorExists($codebase, $fq_classlike_name, $property_name)) {
            return true;
        }

        return null;
    }

    public static function isPropertyVisible(StatementsSource $source, string $fq_classlike_name, string $property_name, bool $read_mode, Context $context, CodeLocation $code_location): ?bool
    {
        if (!$read_mode) {
            return null;
        }

        $codebase = $source->getCodebase();

        if (self::accessorExists($codebase, $fq_classlike_name, $property_name)) {
            return true;
        }

        return null;
    }

    public static function getPropertyType(string $fq_classlike_name, string $property_name, bool $read_mode, ?StatementsSource $source = null, ?Context $context = null): ?Type\Union
    {
        if (!$source || !$read_mode) {
            return null;
        }

        $codebase = $source->getCodebase();

        if (self::accessorExists($codebase, $fq_classlike_name, $property_name)) {
            return $codebase->getMethodReturnType($fq_classlike_name . '::get' . str_replace('_', '', $property_name) . 'Attribute', $fq_classlike_name)
                ?: Type::getMixed();
        }

        return null;
    }

    private static function accessorExists(Codebase $codebase, string $fq_classlike_name, string $property_name): bool
    {
        return $codebase->methodExists($fq_classlike_name . '::get' . str_replace('_', '', $property_name) . 'Attribute');
    }
}
