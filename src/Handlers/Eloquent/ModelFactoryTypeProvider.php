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
    /**
     * @return list<class-string<\Illuminate\Database\Eloquent\Model>>
     * @psalm-external-mutation-free
     */
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
        if (!$source instanceof \Psalm\StatementsSource) {
            return null;
        }

        $classlike = $source->getCodebase()->classlike_storage_provider->get($event->getFqClasslikeName());

        $usesHasFactory = isset($classlike->used_traits[strtolower(HasFactory::class)]);
        if (! $usesHasFactory) {
            return null;
        }

        $hasFactoryProperty = isset($classlike->properties['factory']);
        // Check for static $factory property
        if ($hasFactoryProperty && $classlike->properties['factory']->type !== null) {
            $factoryType = $classlike->properties['factory']->type;
            foreach ($factoryType->getAtomicTypes() as $type) {
                if ($type instanceof Type\Atomic\TNamedObject) {
                    return new Type\Union([new Type\Atomic\TNamedObject($type->value)]);
                }
            }
        }

        // Default to Factory<static>
        return new Type\Union([
            new Type\Atomic\TGenericObject('Illuminate\Database\Eloquent\Factories\Factory', [
                new Type\Union([new Type\Atomic\TNamedObject($event->getFqClasslikeName())]),
            ]),
        ]);
    }
}
