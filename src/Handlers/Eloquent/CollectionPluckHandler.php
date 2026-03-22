<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic\TGenericObject;
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

        $args = $event->getCallArgs();
        if ($args === []) {
            return null;
        }

        $columnName = ModelPropertyResolver::extractStringLiteral($event, $args[0]);
        if ($columnName === null) {
            return null;
        }

        // Resolve the model class from Collection<TKey, TValue> — TValue is the second param.
        // For non-Model collections (e.g. Collection<int, array>), this returns null and we
        // fall back to Psalm's default type inference.
        $templateTypeParameters = $event->getTemplateTypeParameters();
        if ($templateTypeParameters === null || \count($templateTypeParameters) < 2) {
            return null;
        }

        $modelClass = ModelPropertyResolver::extractModelFromUnion($templateTypeParameters[1]);
        if ($modelClass === null) {
            return null;
        }

        $codebase = $event->getSource()->getCodebase();
        $propertyType = ModelPropertyResolver::resolvePropertyType($codebase, $modelClass, $columnName);
        if ($propertyType === null) {
            return null;
        }

        $keyType = Type::getInt();
        if (\count($args) >= 2) {
            $keyType = Type::getArrayKey();
        }

        return new Union([
            new TGenericObject(Collection::class, [$keyType, $propertyType]),
        ]);
    }
}
