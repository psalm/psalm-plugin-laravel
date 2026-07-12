<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Collections;

use Illuminate\Support\Collection;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Union;

/**
 * Narrows `collect($x)` for inputs the stub's own template inference can't bind: null, scalars,
 * and UnitEnum cases.
 *
 * Deliberately does nothing for the no-args call and every other shape - the stub's own
 * widened-but-unbound `object` template branch already infers the sound `Collection<array-key,
 * mixed>` for WeakMap/Jsonable/JsonSerializable/plain objects without this handler's help - see
 * CollectionInputTypeResolver.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/808
 * @see CollectionInputTypeResolver
 */
final class CollectHandler implements FunctionReturnTypeProviderInterface
{
    /**
     * @return list<lowercase-string>
     * @psalm-pure
     */
    #[\Override]
    public static function getFunctionIds(): array
    {
        return ['collect'];
    }

    #[\Override]
    public static function getFunctionReturnType(FunctionReturnTypeProviderEvent $event): ?Union
    {
        $args = $event->getCallArgs();
        if ($args === []) {
            // No-args default `[]` is already bound correctly by the stub template (issue #721).
            return null;
        }

        $source = $event->getStatementsSource();
        $argType = $source->getNodeTypeProvider()->getType($args[0]->value);

        $resolved = CollectionInputTypeResolver::resolve($argType, $source->getCodebase());
        if ($resolved === null) {
            return null;
        }

        [$keyType, $valueType] = $resolved;

        return new Union([new TGenericObject(Collection::class, [$keyType, $valueType])]);
    }
}
