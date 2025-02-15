<?php

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Psalm\Plugin\EventHandler\Event\PropertyExistenceProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyVisibilityProviderEvent;
use Psalm\Plugin\EventHandler\PropertyExistenceProviderInterface;
use Psalm\Plugin\EventHandler\PropertyTypeProviderInterface;
use Psalm\Plugin\EventHandler\PropertyVisibilityProviderInterface;
use Psalm\Type;

use function strtolower;

final class ModelStaticPropertyHandler implements PropertyExistenceProviderInterface, PropertyVisibilityProviderInterface, PropertyTypeProviderInterface
{
    /** @return array<string> */
    public static function getClassLikeNames(): array
    {
        return [Model::class];
    }

    /** @inheritDoc */
    public static function doesPropertyExist(PropertyExistenceProviderEvent $event): ?bool
    {
        if (!$event->getSource()) {
            return null;
        }

        $classlike = $event->getSource()->getCodebase()->classlike_storage_provider->get($event->getFqClasslikeName());

        return match ($event->getPropertyName()) {
            'factory' => isset($classlike->used_traits[strtolower(HasFactory::class)]) ?: null,
            default => null,
        };
    }

    #[\Override]
    public static function isPropertyVisible(PropertyVisibilityProviderEvent $event): ?bool
    {
        $classlike = $event->getSource()->getCodebase()->classlike_storage_provider->get($event->getFqClasslikeName());

        return match ($event->getPropertyName()) {
            'factory' => isset($classlike->used_traits[strtolower(HasFactory::class)]) ?: null,
            default => null,
        };
    }

    #[\Override]
    public static function getPropertyType(PropertyTypeProviderEvent $event): ?Type\Union
    {
        // Case HasFactory: Let the HasFactory trait handle the type information
        return null;
    }
}
