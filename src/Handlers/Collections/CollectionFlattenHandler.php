<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Collections;

use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Illuminate\Support\LazyCollection;
use PhpParser\Node\Scalar\LNumber;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Union;

/**
 * Narrows Collection::flatten() return type when the depth argument is a known literal.
 *
 * Laravel's flatten() docblock currently returns `static<int, mixed>`, which erases generic info.
 * This handler recovers TValue for the most common cases:
 *
 * - flatten(1) on Collection<K, Collection<K2, V>> or Collection<K, array<K2, V>> → Collection<int, V>
 * - collapse() on Collection<K, Collection<K2, V>> or Collection<K, array<K2, V>> → Collection<int, V>
 *
 * collapse() is semantically equivalent to flatten(1) — both unwrap one level of nesting.
 * For flatten() with unknown/infinite depth, defers to Psalm's default.
 * Note: flatten(0) is equivalent to flatten(INF) in Laravel, not a no-op.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/617
 * @internal
 */
final class CollectionFlattenHandler implements MethodReturnTypeProviderInterface
{
    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        // EloquentCollection is excluded: its flatten()/collapse() call $this->toBase(),
        // returning Support\Collection (not static), so the handler's is_static: true would
        // be wrong. Also, TValue is constrained to Model, so nested collections can't occur.
        return [Collection::class, LazyCollection::class];
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $method = $event->getMethodNameLowercase();

        // collapse() is always single-level — no depth argument needed
        $isSingleLevelFlatten = $method === 'collapse'
            || ($method === 'flatten' && self::extractLiteralDepth($event) === 1);

        if (!$isSingleLevelFlatten) {
            return null;
        }

        $templateTypeParameters = $event->getTemplateTypeParameters();
        if ($templateTypeParameters === null || \count($templateTypeParameters) < 2) {
            return null;
        }

        $tValue = $templateTypeParameters[1];
        $innerValue = self::extractInnerValue($tValue);
        if (!$innerValue instanceof \Psalm\Type\Union) {
            return null;
        }

        $className = $event->getCalledFqClasslikeName() ?? $event->getFqClasslikeName();

        return new Union([
            new TGenericObject($className, [Type::getInt(), $innerValue], is_static: true),
        ]);
    }

    /**
     * Extract the literal integer depth from the first argument, if present.
     *
     * Returns null for: no arguments (= INF depth), non-literal expressions, non-int values.
     */
    private static function extractLiteralDepth(MethodReturnTypeProviderEvent $event): ?int
    {
        $args = $event->getCallArgs();
        if ($args === []) {
            return null; // no argument = INF depth, can't narrow
        }

        $depthArg = $args[0]->value;

        if ($depthArg instanceof LNumber) {
            return $depthArg->value;
        }

        return null;
    }

    /**
     * Extract the value type from a collection-like or array-like TValue.
     *
     * Handles: Collection<K, V>, array<K, V>, array{...} (via getGenericValueType).
     * @psalm-mutation-free
     */
    private static function extractInnerValue(Union $tValue): ?Union
    {
        if (!$tValue->isSingle()) {
            return null; // union TValue like Collection|array — too complex, bail
        }

        $atomic = $tValue->getSingleAtomic();

        // Collection<K, V>, LazyCollection<K, V>, or any Enumerable — extract V (index 1)
        if ($atomic instanceof TGenericObject && \count($atomic->type_params) >= 2 && \is_a($atomic->value, Enumerable::class, allow_string: true)) {
            return $atomic->type_params[1];
        }

        // array<K, V> — extract V (index 1)
        if ($atomic instanceof TArray && \count($atomic->type_params) >= 2) {
            return $atomic->type_params[1];
        }

        // array{key: type, ...} — get the generic value type
        if ($atomic instanceof TKeyedArray) {
            return $atomic->getGenericValueType();
        }

        return null;
    }
}
