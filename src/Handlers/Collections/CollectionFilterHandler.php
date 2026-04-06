<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Collections;

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TFalse;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNonEmptyArray;
use Psalm\Type\Atomic\TNonFalsyString;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Union;

/**
 * Narrows Collection::filter() return type when called without a callback.
 *
 * Laravel's filter() with no arguments calls array_filter(), which removes all
 * falsy values. The most impactful narrowing is removing `null` and `false` from
 * TValue, covering the vast majority of real-world usage (e.g., ->map()->filter()).
 *
 * Not covered (intentionally, 80/20):
 * - Numeric falsy types (0, 0.0) are not narrowed — Psalm has no `non-zero-int` atomic
 *   type, so the complexity of constructing `int<min, -1>|int<1, max>` isn't worth it.
 * - `Enumerable` type-hints — the handler only fires for Collection and LazyCollection
 *   concrete types, not the Enumerable interface.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/441
 */
final class CollectionFilterHandler implements MethodReturnTypeProviderInterface
{
    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [Collection::class, LazyCollection::class];
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if ($event->getMethodNameLowercase() !== 'filter') {
            return null;
        }

        // Only narrow when called with no arguments (or explicit null).
        // With a callback, we can't know what it filters — let Psalm use the default.
        if (! self::isCalledWithoutCallback($event)) {
            return null;
        }

        $templateTypeParameters = $event->getTemplateTypeParameters();
        if ($templateTypeParameters === null || \count($templateTypeParameters) < 2) {
            return null;
        }

        $tKey = $templateTypeParameters[0];
        $tValue = $templateTypeParameters[1];

        $narrowed = self::removeFalsyTypes($tValue);
        if (!$narrowed instanceof \Psalm\Type\Union) {
            return null; // nothing to narrow, or would become empty
        }

        // Return the same Collection subclass with narrowed TValue.
        // is_static: true preserves the `&static` intersection, matching filter()'s `return static`.
        $className = $event->getCalledFqClasslikeName() ?? $event->getFqClasslikeName();

        return new Union([
            new TGenericObject($className, [$tKey, $narrowed], is_static: true),
        ]);
    }

    /**
     * Check if filter() was called with no arguments or with an explicit null literal.
     */
    private static function isCalledWithoutCallback(MethodReturnTypeProviderEvent $event): bool
    {
        $args = $event->getCallArgs();

        if ($args === []) {
            return true;
        }

        // filter(null) — explicit null is equivalent to no callback
        if (\count($args) === 1) {
            $argValue = $args[0]->value;
            if ($argValue instanceof \PhpParser\Node\Expr\ConstFetch
                && \strtolower($argValue->name->toString()) === 'null') {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove falsy types and narrow remaining types to their non-empty variants.
     *
     * - Removes `null` and `false` entirely
     * - Narrows `string` → `non-empty-string`, `array` → `non-empty-array`
     *
     * Returns null if nothing changed or narrowing would leave the union empty.
     * @psalm-mutation-free
     */
    private static function removeFalsyTypes(Union $type): ?Union
    {
        $atomics = $type->getAtomicTypes();
        $filtered = [];
        $changed = false;

        foreach ($atomics as $atomic) {
            if ($atomic instanceof TNull || $atomic instanceof TFalse) {
                $changed = true;
                continue;
            }

            $narrowed = self::narrowAtomic($atomic);
            if ($narrowed !== $atomic) {
                $changed = true;
            }

            $filtered[] = $narrowed;
        }

        if (! $changed || $filtered === []) {
            return null;
        }

        return new Union($filtered);
    }

    /**
     * Narrow an atomic type to its non-empty variant where possible.
     *
     * array_filter() removes "", "0", and [] — so `string` becomes `non-falsy-string`
     * (excludes both "" and "0") and `array` becomes `non-empty-array`.
     * Already-narrow subtypes are left as-is.
     *
     * Not narrowed: int/float — Psalm has no `non-zero-int` atomic type, and constructing
     * `int<min, -1>|int<1, max>` adds complexity for a rare use case.
     *
     * @psalm-pure
     */
    private static function narrowAtomic(Atomic $atomic): Atomic
    {
        // Narrow TString but not its subclasses (TNonFalsyString, TNonEmptyString, TLiteralString, etc.)
        if ($atomic::class === TString::class) {
            return new TNonFalsyString();
        }

        // Narrow TArray but not TNonEmptyArray or other subclasses
        if ($atomic::class === TArray::class) {
            return new TNonEmptyArray($atomic->type_params);
        }

        return $atomic;
    }
}
