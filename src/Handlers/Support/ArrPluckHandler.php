<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Support;

use Illuminate\Support\Arr;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\ModelPropertyResolver;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Union;

/**
 * Narrows `Arr::pluck($array, $value, $key = null)` when $array's element type is a
 * single known Eloquent model, using @property annotations — the static-call
 * counterpart of {@see \Psalm\LaravelPlugin\Handlers\Eloquent\BuilderPluckHandler} and
 * {@see \Psalm\LaravelPlugin\Handlers\Collections\CollectionPluckHandler}. `Arr::pluck()`
 * has no generic receiver, so the model is read from the first argument's element type
 * ({@see ModelPropertyResolver::extractModelFromIterableValueType()}) instead of a
 * template parameter.
 *
 * `Arr::pluck()` is not stubbed (`stubs/common/Support/Arr.phpstub` is an empty class),
 * so without this handler Psalm falls back to reflection's plain `@return array`.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1379
 * @internal
 */
final class ArrPluckHandler implements MethodReturnTypeProviderInterface
{
    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [Arr::class];
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if ($event->getMethodNameLowercase() !== 'pluck') {
            return null;
        }

        $args = $event->getCallArgs();
        if (\count($args) < 2) {
            // Missing the required $value argument — not a valid call to narrow.
            return null;
        }

        $nodeTypeProvider = $event->getSource()->getNodeTypeProvider();

        $modelClass = ModelPropertyResolver::extractModelFromIterableValueType(
            $nodeTypeProvider->getType($args[0]->value),
        );
        if ($modelClass === null) {
            return null;
        }

        $resolved = ModelPropertyResolver::resolvePluckColumnTypes(
            valueArg: $args[1],
            keyArg: $args[2] ?? null,
            modelClass: $modelClass,
            nodeTypeProvider: $nodeTypeProvider,
            codebase: $event->getSource()->getCodebase(),
        );
        if ($resolved === null) {
            return null;
        }

        [$keyType, $valueType] = $resolved;

        // No $key argument: Laravel pushes onto $results[] (sequential int keys) —
        // a list, not a general array<int, V>. See Arr::pluck() source.
        if (!isset($args[2])) {
            return Type::getList($valueType);
        }

        return new Union([new TArray([$keyType, $valueType])]);
    }
}
