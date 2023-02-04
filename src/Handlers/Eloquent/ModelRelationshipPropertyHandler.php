<?php

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use PhpParser;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Providers\ModelStubProvider;
use Psalm\Plugin\EventHandler\Event\PropertyExistenceProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyVisibilityProviderEvent;
use Psalm\Plugin\EventHandler\PropertyExistenceProviderInterface;
use Psalm\Plugin\EventHandler\PropertyTypeProviderInterface;
use Psalm\Plugin\EventHandler\PropertyVisibilityProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Union;

use function in_array;

class ModelRelationshipPropertyHandler implements
    PropertyExistenceProviderInterface,
    PropertyVisibilityProviderInterface,
    PropertyTypeProviderInterface
{
    /** @return list<class-string<\Illuminate\Database\Eloquent\Model>> */
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

        $codebase = $source->getCodebase();
        $fq_classlike_name = $event->getFqClasslikeName();
        $property_name = $event->getPropertyName();

        if (self::relationExists($codebase, $fq_classlike_name, $property_name)) {
            return true;
        }

        $class_like_storage = $codebase->classlike_storage_provider->get($fq_classlike_name);

        if (isset($class_like_storage->pseudo_property_get_types['$' . $property_name])) {
            return null;
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

        if (self::relationExists($codebase, $fq_classlike_name, $property_name)) {
            return true;
        }

        $class_like_storage = $codebase->classlike_storage_provider->get($fq_classlike_name);

        if (isset($class_like_storage->pseudo_property_get_types['$' . $property_name])) {
            return null;
        }

        return null;
    }

    /**
     * @param  array<PhpParser\Node\Arg>    $call_args
     *
     * @return ?Union
     */
    public static function getPropertyType(PropertyTypeProviderEvent $event): ?Union
    {
        $source = $event->getSource();

        if (!$source || !$event->isReadMode()) {
            return null;
        }

        $codebase = $source->getCodebase();
        $fq_classlike_name = $event->getFqClasslikeName();
        $property_name = $event->getPropertyName();

        if (self::relationExists($codebase, $fq_classlike_name, $property_name)) {
            $methodReturnType = $codebase->getMethodReturnType($fq_classlike_name . '::' . $property_name, $fq_classlike_name);
            if (!$methodReturnType) {
                return Type::getMixed();
            }

            /** @var Union|null $modelType */
            $modelType = null;
            /** @var TGenericObject|null $relationType */
            $relationType = null;

            // In order to get the property value, we need to decipher the generic relation object
            foreach ($methodReturnType->getAtomicTypes() as $atomicType) {
                if (!$atomicType instanceof TGenericObject) {
                    continue;
                }

                $relationType = $atomicType;

                foreach ($atomicType->type_params as $childNode) {
                    foreach ($childNode->getAtomicTypes() as $atomicType) {
                        if (!$atomicType instanceof Type\Atomic\TNamedObject) {
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

            if ($modelType && $relationType && in_array($relationType->value, $relationsThatReturnACollection, true)) {
                $returnType = new Union([
                    new TGenericObject(Collection::class, [
                        new Union([new TInt()]),
                        $modelType
                    ]),
                ]);
            }

            return $returnType ?: Type::getMixed();
        }

        return null;
    }

    /**
     * @param Codebase $codebase
     * @param string $fq_classlike_name
     * @param string $property_name
     *
     * @return bool
     */
    private static function relationExists(Codebase $codebase, string $fq_classlike_name, string $property_name): bool
    {
        // @todo: ensure this is a relation method
        return $codebase->methodExists($fq_classlike_name . '::' . $property_name);
    }
}
