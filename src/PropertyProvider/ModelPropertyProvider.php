<?php

namespace Psalm\LaravelPlugin\PropertyProvider;

use PhpParser;
use Psalm\Context;
use Psalm\CodeLocation;
use Psalm\Type;
use Psalm\StatementsSource;

class ModelPropertyProvider
    implements \Psalm\Plugin\Hook\PropertyExistenceProviderInterface,
        \Psalm\Plugin\Hook\PropertyVisibilityProviderInterface,
        \Psalm\Plugin\Hook\PropertyTypeProviderInterface
{
    public static function getClassLikeNames() : array
    {
        return \Psalm\LaravelPlugin\AbstractPlugin::$model_classes;
    }

    /**
     * @return ?bool
     */
    public static function doesPropertyExist(
        string $fq_classlike_name,
        string $property_name,
        bool $read_mode,
        StatementsSource $source = null,
        Context $context = null,
        CodeLocation $code_location = null
    ) {
        if (!$source || !$read_mode) {
            return null;
        }

        $codebase = $source->getCodebase();

        $class_like_storage = $codebase->classlike_storage_provider->get($fq_classlike_name);

        if ($codebase->methodExists($fq_classlike_name . '::' . $property_name)) {
            return true;
        }

        if ($codebase->methodExists($fq_classlike_name . '::get' . str_replace('_', '', $property_name) . 'Attribute')) {
            return true;
        }

        if (isset($class_like_storage->pseudo_property_get_types['$' . $property_name])) {
            return null;
        }

        return null;
    }

    /**
     * @return ?bool
     */
    public static function isPropertyVisible(
        StatementsSource $source,
        string $fq_classlike_name,
        string $property_name,
        bool $read_mode,
        Context $context,
        CodeLocation $code_location = null
    ) {
        if (!$read_mode) {
            return null;
        }

        $codebase = $source->getCodebase();

        $class_like_storage = $codebase->classlike_storage_provider->get($fq_classlike_name);

        if ($codebase->methodExists($fq_classlike_name . '::' . $property_name)) {
            return true;
        }

        if ($codebase->methodExists($fq_classlike_name . '::get' . str_replace('_', '', $property_name) . 'Attribute')) {
            return true;
        }

        if (isset($class_like_storage->pseudo_property_get_types['$' . $property_name])) {
            return null;
        }

        return null;
    }

    /**
     * @param  array<PhpParser\Node\Arg>    $call_args
     *
     * @return ?Type\Union
     */
    public static function getPropertyType(
        string $fq_classlike_name,
        string $property_name,
        bool $read_mode,
        StatementsSource $source = null,
        Context $context = null
    ) {
        if (!$source || !$read_mode) {
            return null;
        }

        $codebase = $source->getCodebase();

        $class_like_storage = $codebase->classlike_storage_provider->get($fq_classlike_name);

        if ($codebase->methodExists($fq_classlike_name . '::' . $property_name)) {
            return $codebase->getMethodReturnType($fq_classlike_name . '::' . $property_name, $fq_classlike_name)
                ?: Type::getMixed();
        }

        if ($codebase->methodExists($fq_classlike_name . '::get' . str_replace('_', '', $property_name) . 'Attribute')) {
            return $codebase->getMethodReturnType($fq_classlike_name . '::get' . str_replace('_', '', $property_name) . 'Attribute', $fq_classlike_name)
                ?: Type::getMixed();
        }
    }
}