<?php

declare(strict_types=1);

namespace Fx\Plugins;

use Psalm\Plugin\EventHandler\Event\PropertyExistenceProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyVisibilityProviderEvent;
use Psalm\Plugin\EventHandler\PropertyExistenceProviderInterface;
use Psalm\Plugin\EventHandler\PropertyVisibilityProviderInterface;

/**
 * Third-party stand-in for a file-based plugin that asserts a property the class never declares (#2129): the
 * existence provider says `Fx\Provided::$ghost` exists and the visibility provider says it is not
 * visible, so the assignment reaches `__set` (the synthesized, unmapped sealed issue) while the
 * `UndefinedMagicPropertyAssignment` twin stays silent because `propertyExists()` is true.
 */
final class ProvidedPropertyPlugin implements PropertyExistenceProviderInterface, PropertyVisibilityProviderInterface
{
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return ['Fx\Provided'];
    }

    #[\Override]
    public static function doesPropertyExist(PropertyExistenceProviderEvent $event): ?bool
    {
        return $event->getPropertyName() === 'ghost' ? true : null;
    }

    #[\Override]
    public static function isPropertyVisible(PropertyVisibilityProviderEvent $event): ?bool
    {
        return $event->getPropertyName() === 'ghost' ? false : null;
    }
}
