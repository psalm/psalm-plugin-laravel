<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Psalm\Codebase;
use Psalm\NodeTypeProvider;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TLiteralInt;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Union;

/**
 * Tracks Factory plurality across `count()` / `times()` calls so that
 * `create()` / `make()` / `createQuietly()` resolve to either a single model
 * or a collection.
 *
 * The Factory stub declares a second template `TCount` (default `null`) and
 * uses a conditional return on `create`/`make`/`createQuietly`:
 *
 *     (TCount is null|0|1 ? TModel : Collection<int, TModel>)
 *
 * The handler maps literal arguments `0` and `1` to a plural sentinel because
 * Laravel's runtime returns a `Collection` for any non-null `count`, including
 * 0 and 1. The conditional's broader `null|0|1` set exists so an unbound
 * `TCount` (user subclass extending `Factory<X>` with one template arg) still
 * evaluates to single when `count()`/`times()` was never called.
 *
 * For the conditional to fire, the receiver type needs both template params
 * populated. User-defined factory subclasses (`class UserFactory extends
 * Factory<User>`) only specify one template arg, so a `static<TModel,
 * TNewCount>` return from `count()` collapses the second arg back to the
 * subclass default and the plurality is lost.
 *
 * To work around that, this handler returns the BASE `Factory<TModel,
 * TCountLiteral>` (not the user subclass) for `count()` / `times()` calls.
 * `state()`, `for*()`, `has*()`, etc., still preserve `TCount` automatically
 * because they declare `@return static`, which the receiver type carries
 * forward.
 *
 * Trade-off: subclass-specific methods are not callable AFTER `count()` /
 * `times()`. Calling them BEFORE the count works fine. This matches Larastan's
 * behaviour and is the common Laravel pattern.
 *
 * Reserved space for #696 (`for*()` / `has*()` magic methods): those will be
 * declared via a separate `MethodExistenceProvider` returning `static`, so
 * plurality propagates through the magic chain unchanged.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/693
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/696
 * @internal
 */
final class FactoryCountTypeProvider implements MethodReturnTypeProviderInterface
{
    /**
     * Cached single-instance Unions for the three TCount shapes the handler
     * produces. The handler fires on every Factory::count()/times() call in
     * the analysed codebase, so re-allocating these per call adds up.
     */
    private static ?Union $singleCountUnion = null;

    private static ?Union $pluralCountUnion = null;

    private static ?Union $unknownCountUnion = null;

    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [Factory::class];
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $methodName = $event->getMethodNameLowercase();
        if ($methodName !== 'count' && $methodName !== 'times') {
            return null;
        }

        $source = $event->getSource();
        $modelType = self::resolveModelType($event, $source->getCodebase());
        if (!$modelType instanceof Union) {
            return null;
        }

        $countType = self::resolveCountArgument($event->getCallArgs(), $source->getNodeTypeProvider());

        return new Union([
            new TGenericObject(Factory::class, [$modelType, $countType]),
        ]);
    }

    /**
     * Find TModel by trying:
     *  1. The receiver's template parameters (when the type is generic, e.g.
     *     `Factory<Customer>` declared via `@var`).
     *  2. The called class's `extends Factory<X>` binding (covers user
     *     subclasses like `class UserFactory extends Factory<User>` and
     *     static calls `UserFactory::times(N)`).
     *  3. Last-resort fallback to base `Model`. Without this, an unbound
     *     receiver (bare `Factory` with no template params and no subclass
     *     binding — produced via `__callStatic` on a Facade, etc.) collapses
     *     through the stub's `@return Factory<TModel, int|null>` and `make()`'s
     *     conditional picks the single-model branch. Falling back to `Model`
     *     keeps the `TCount` narrowing intact so `count(N)->make()` still
     *     resolves to `Collection<int, Model>` (downgraded but iterable).
     *
     * @psalm-mutation-free
     */
    private static function resolveModelType(MethodReturnTypeProviderEvent $event, Codebase $codebase): ?Union
    {
        $templateParams = $event->getTemplateTypeParameters();

        if ($templateParams !== null) {
            $modelType = $templateParams[0] ?? null;
            if (self::isModelType($modelType, $codebase)) {
                return $modelType;
            }
        }

        $calledClass = $event->getCalledFqClasslikeName() ?? $event->getFqClasslikeName();
        $resolved = self::resolveModelFromClass($calledClass, $codebase);
        if ($resolved instanceof Union) {
            return $resolved;
        }

        return new Union([new TNamedObject(Model::class)]);
    }

    /**
     * Lineage check via Psalm's class storage. `is_a()` was previously used here
     * but breaks for classes Psalm scans yet PHP's autoloader cannot resolve
     * at handler runtime (e.g. PHPT fixture models declared inline).
     *
     * @psalm-mutation-free
     */
    private static function isModelType(?Union $type, Codebase $codebase): bool
    {
        if (!$type instanceof Union) {
            return false;
        }

        foreach ($type->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject) {
                return false;
            }

            if ($atomic->value === Model::class) {
                continue;
            }

            try {
                $storage = $codebase->classlike_storage_provider->get($atomic->value);
            } catch (\InvalidArgumentException) {
                return false;
            }

            if (!isset($storage->parent_classes[\strtolower(Model::class)])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Walk `template_extended_params` to find what a class binds Factory's
     * `TModel` to. Returns null if the class isn't a Factory subclass or if
     * the binding can't be resolved.
     *
     * `ClassLikeStorageProvider::get()` lowercases internally, so the FQCN is
     * passed through untouched. `template_extended_params` is keyed on the
     * canonical class name (`$parent_storage->name`), which matches
     * `Factory::class` for any vendor-stable Laravel install.
     *
     * @psalm-mutation-free
     */
    private static function resolveModelFromClass(string $factoryClass, Codebase $codebase): ?Union
    {
        try {
            $storage = $codebase->classlike_storage_provider->get($factoryClass);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $extended = $storage->template_extended_params[Factory::class] ?? null;
        if (!\is_array($extended)) {
            return null;
        }

        $modelType = $extended['TModel'] ?? null;

        return self::isModelType($modelType, $codebase) ? $modelType : null;
    }

    /**
     * Map the count argument to the TCount template type:
     *  - missing or `null` literal → `null` (single-mode branch)
     *  - any non-null int (literal `0`/`1`/`N`, plain `int`, `int<a, b>`,
     *    `positive-int`, etc.) → literal `2` (collection branch — Laravel's
     *    `Factory::make()` returns a collection for every non-null count)
     *  - mixed `int|null` or any non-int/non-null atomic → `null|2` (forces
     *    the conditional to a union of both branches)
     *
     * Literal `2` is just a representative value: the conditional only
     * distinguishes `null|0|1` from "anything else", and `2` falls into the
     * "anything else" side regardless of the exact runtime count.
     *
     * @param list<\PhpParser\Node\Arg> $callArgs
     */
    private static function resolveCountArgument(array $callArgs, NodeTypeProvider $nodeTypeProvider): Union
    {
        $arg = $callArgs[0] ?? null;
        if ($arg === null) {
            return self::singleCountUnion();
        }

        $argType = $nodeTypeProvider->getType($arg->value);
        if (!$argType instanceof Union) {
            return self::unknownCountUnion();
        }

        $hasNull = false;
        $hasNonNullInt = false;

        foreach ($argType->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNull) {
                $hasNull = true;
                continue;
            }

            // TLiteralInt extends TInt, so this branch also covers literal
            // ints (`0`, `1`, `N`), TIntRange, and any future TInt subclass.
            if ($atomic instanceof TInt) {
                $hasNonNullInt = true;
                continue;
            }

            // Unexpected atomic (e.g., string from a misuse via __callStatic).
            // Fall back to the union shape so neither branch is silently dropped.
            return self::unknownCountUnion();
        }

        return match (true) {
            $hasNull && !$hasNonNullInt => self::singleCountUnion(),
            $hasNonNullInt && !$hasNull => self::pluralCountUnion(),
            default => self::unknownCountUnion(),
        };
    }

    /** @psalm-external-mutation-free */
    private static function singleCountUnion(): Union
    {
        return self::$singleCountUnion ??= new Union([new TNull()]);
    }

    /** @psalm-external-mutation-free */
    private static function pluralCountUnion(): Union
    {
        return self::$pluralCountUnion ??= new Union([new TLiteralInt(2)]);
    }

    /** @psalm-external-mutation-free */
    private static function unknownCountUnion(): Union
    {
        return self::$unknownCountUnion ??= new Union([new TNull(), new TLiteralInt(2)]);
    }
}
