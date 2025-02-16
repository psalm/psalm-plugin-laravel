<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Psalm\Plugin\EventHandler\Event\PropertyTypeProviderEvent;
use Psalm\Plugin\EventHandler\PropertyTypeProviderInterface;
use Psalm\LaravelPlugin\Providers\ModelStubProvider;
use Psalm\Type;

use function strtolower;

final class ModelFactoryTypeProvider implements PropertyTypeProviderInterface
{
    /** @return array<string> */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return ModelStubProvider::getModelClasses();
    }

    #[\Override]
    public static function getPropertyType(PropertyTypeProviderEvent $event): ?Type\Union
    {
        if ($event->getPropertyName() !== 'factory') {
            return null;
        }

        $source = $event->getSource();
        if ($source === null) {
            return null;
        }

        $classlike = $source->getCodebase()->classlike_storage_provider->get($event->getFqClasslikeName());

        $usesHasFactory = isset($classlike->used_traits[strtolower(HasFactory::class)]);
        $hasFactoryProperty = isset($classlike->properties['factory']);
        if (!$usesHasFactory && !$hasFactoryProperty) {
            return null;
        }

        // Check for @use HasFactory<SomeFactory> annotation
        foreach ($classlike->docblock_type_tags['use'] ?? [] as $useTag) {
            if (
                $useTag->type instanceof Type\Atomic\TGenericObject
                && $useTag->type->value === HasFactory::class
                && isset($useTag->type->type_params[0])
            ) {
                return new Type\Union([$useTag->type->type_params[0]]);
            }
        }

        // Default to Factory<static>
        return new Type\Union([
            new Type\Atomic\TGenericObject('Illuminate\Database\Eloquent\Factories\Factory', [
                new Type\Union([new Type\Atomic\TNamedObject($event->getFqClasslikeName())])
            ])
        ]);
    }
}
