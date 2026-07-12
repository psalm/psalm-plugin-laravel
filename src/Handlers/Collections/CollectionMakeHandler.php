<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Collections;

use Illuminate\Support\LazyCollection;
use Illuminate\Support\Traits\EnumeratesValues;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Union;

/**
 * Narrows `Collection::make($x)` / `LazyCollection::make($x)` (and subclass calls, e.g.
 * `\Illuminate\Database\Eloquent\Collection::make($x)`) for inputs the stub's own template
 * inference can't bind: null, scalars, and UnitEnum cases. Every other shape - WeakMap,
 * Jsonable, JsonSerializable, plain objects - defers to the stub's own widened-but-unbound
 * `object` template branch, which already infers the sound `Collection<array-key, mixed>`.
 *
 * Psalm resolves a static call by first trying the CALLED class against the provider registry;
 * on a miss it falls back to `getDeclaringMethodId()` and retries the registry with that class
 * (see `ExistingAtomicStaticCallAnalyzer` + `Populator::inheritMethodsFromParent`). `make()` is
 * declared once, in `EnumeratesValues`, so for any class using the trait without overriding
 * `make()` the declaring class is the trait itself - registering `EnumeratesValues::class` alone
 * covers `Collection::make()` and every subclass (`EloquentCollection`, user collections).
 * `LazyCollection::class` is registered separately because `LazyCollection` re-declares `make()`
 * itself, so its declaring class is `LazyCollection` and the trait fallback never fires for it.
 * `getCalledFqClasslikeName()` carries the originally-called subclass through either path;
 * `?? getFqClasslikeName()` covers the direct-registration (`LazyCollection`) case.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/808
 * @see CollectionInputTypeResolver
 */
final class CollectionMakeHandler implements MethodReturnTypeProviderInterface
{
    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [LazyCollection::class, EnumeratesValues::class];
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if ($event->getMethodNameLowercase() !== 'make') {
            return null;
        }

        $args = $event->getCallArgs();
        if ($args === []) {
            // No-args default `[]` is already bound correctly by the stub template.
            return null;
        }

        $source = $event->getSource();
        $argType = $source->getNodeTypeProvider()->getType($args[0]->value);

        $resolved = CollectionInputTypeResolver::resolve($argType, $source->getCodebase());
        if ($resolved === null) {
            return null;
        }

        [$keyType, $valueType] = $resolved;

        $calledClass = $event->getCalledFqClasslikeName() ?? $event->getFqClasslikeName();

        // is_static: true preserves the `&static` intersection, matching the stub's `@return static<...>`.
        return new Union([new TGenericObject($calledClass, [$keyType, $valueType], is_static: true)]);
    }
}
