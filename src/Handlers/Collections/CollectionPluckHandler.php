<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Collections;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Psalm\LaravelPlugin\Util\ModelPropertyResolver;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Union;

/**
 * Narrows the return type of Collection::pluck() when the collection contains
 * Eloquent models, using model @property annotations to infer the value type.
 *
 * Handles the common pattern: User::all()->pluck('email') or
 * User::query()->get()->pluck('email').
 *
 * Both Support\Collection and Eloquent\Collection are registered because Psalm
 * dispatches handlers based on the called class, not the declaring class.
 *
 * Not covered: Relation::pluck() (e.g. $user->phone()->pluck('number')) — relationship
 * query builders are not intercepted by this handler.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/486
 * @internal
 */
final class CollectionPluckHandler implements MethodReturnTypeProviderInterface
{
    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [Collection::class, EloquentCollection::class];
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if ($event->getMethodNameLowercase() !== 'pluck') {
            return null;
        }

        // Collection<TKey, TValue> — TValue (the model) is template param at index 1.
        // For non-Model collections, resolvePluckReturnType returns null and Psalm uses
        // its default type inference.
        return ModelPropertyResolver::resolvePluckReturnType(
            args: $event->getCallArgs(),
            templateParams: $event->getTemplateTypeParameters(),
            modelTemplateIndex: 1,
            nodeTypeProvider: $event->getSource()->getNodeTypeProvider(),
            codebase: $event->getSource()->getCodebase(),
        );
    }
}
