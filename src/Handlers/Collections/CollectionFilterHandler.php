<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Collections;

use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use PhpParser\Node\Expr\ConstFetch;
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
 * Narrows Collection::filter() and Collection::whereNotNull() return types.
 *
 * filter() without a callback:
 *   Calls array_filter(), removing all falsy values. Removes `null` and `false` from
 *   TValue and narrows `string` → `non-falsy-string`, `array` → `non-empty-array`.
 *
 * whereNotNull() without a key argument:
 *   Removes only `null` from TValue (does not narrow other falsy types).
 *
 * Not covered (intentionally, 80/20):
 * - Numeric falsy types (0, 0.0) are not narrowed — Psalm has no `non-zero-int` atomic
 *   type, so the complexity of constructing `int<min, -1>|int<1, max>` isn't worth it.
 * - `Enumerable` type-hints — the handler only fires for Collection and LazyCollection
 *   concrete types, not the Enumerable interface.
 * - whereNotNull($key) with a string key — we don't narrow TValue when filtering by a
 *   nested field key, since the item type itself is unchanged.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/441
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/706
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

    /** @psalm-mutation-free */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $method = $event->getMethodNameLowercase();

        if ($method === 'filter') {
            return self::handleFilter($event);
        }

        if ($method === 'wherenotnull') {
            return self::handleWhereNotNull($event);
        }

        return null;
    }

    /** @psalm-mutation-free */
    private static function handleFilter(MethodReturnTypeProviderEvent $event): ?Union
    {
        // Only narrow when called with no arguments (or explicit null).
        // With a callback, we can't know what it filters — let Psalm use the default.
        if (! self::isCalledWithoutArgOrNull($event)) {
            return null;
        }

        $templateTypeParameters = $event->getTemplateTypeParameters();
        if ($templateTypeParameters === null || \count($templateTypeParameters) < 2) {
            return null;
        }

        $tKey = $templateTypeParameters[0];
        $tValue = $templateTypeParameters[1];

        $narrowed = self::removeFalsyTypes($tValue);
        if (! $narrowed instanceof Union) {
            return null; // nothing to narrow, or would become empty
        }

        return self::buildNarrowedReturn($event, $tKey, $narrowed);
    }

    /** @psalm-mutation-free */
    private static function handleWhereNotNull(MethodReturnTypeProviderEvent $event): ?Union
    {
        // Only narrow when called with no key (or explicit null key).
        // With a string key, whereNotNull filters by a nested field — TValue type is unchanged.
        if (! self::isCalledWithoutArgOrNull($event)) {
            return null;
        }

        $templateTypeParameters = $event->getTemplateTypeParameters();
        if ($templateTypeParameters === null || \count($templateTypeParameters) < 2) {
            return null;
        }

        $tKey = $templateTypeParameters[0];
        $tValue = $templateTypeParameters[1];

        $narrowed = self::removeNullType($tValue);
        if (! $narrowed instanceof Union) {
            return null; // nothing to narrow, or would become empty
        }

        return self::buildNarrowedReturn($event, $tKey, $narrowed);
    }

    /**
     * Build the narrowed return type with the same Collection subclass and is_static.
     * @psalm-mutation-free
     */
    private static function buildNarrowedReturn(
        MethodReturnTypeProviderEvent $event,
        Union $tKey,
        Union $narrowedValue,
    ): Union {
        // is_static: true preserves the `&static` intersection, matching `return static`.
        $className = $event->getCalledFqClasslikeName() ?? $event->getFqClasslikeName();

        return new Union([
            new TGenericObject($className, [$tKey, $narrowedValue], is_static: true),
        ]);
    }

    /**
     * Check if the method was called with no arguments or with an explicit null literal.
     *
     * Both filter(null) and whereNotNull(null) treat an explicit null argument as
     * equivalent to no argument — it means "no callback" and "filter by value itself",
     * respectively.
     *
     * @psalm-mutation-free
     */
    private static function isCalledWithoutArgOrNull(MethodReturnTypeProviderEvent $event): bool
    {
        $args = $event->getCallArgs();

        if ($args === []) {
            return true;
        }

        // Explicit null literal is equivalent to no argument for both filter() and whereNotNull()
        if (\count($args) === 1) {
            $argValue = $args[0]->value;
            if ($argValue instanceof ConstFetch
                && $argValue->name->toLowerString() === 'null') {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove only `null` from the union type. Used for whereNotNull() narrowing.
     *
     * Unlike removeFalsyTypes(), this does not remove `false` or narrow strings/arrays,
     * since whereNotNull() only guarantees items are !== null.
     *
     * Returns null if nothing changed or narrowing would leave the union empty.
     * @psalm-mutation-free
     */
    private static function removeNullType(Union $type): ?Union
    {
        $atomics = $type->getAtomicTypes();
        $filtered = [];
        $changed = false;

        foreach ($atomics as $atomic) {
            if ($atomic instanceof TNull) {
                $changed = true;
                continue;
            }

            $filtered[] = $atomic;
        }

        if (! $changed || $filtered === []) {
            return null;
        }

        return new Union($filtered);
    }

    /**
     * Remove falsy types and narrow remaining types to their non-empty variants.
     *
     * - Removes `null` and `false` entirely
     * - Narrows `string` → `non-falsy-string`, `array` → `non-empty-array`
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
