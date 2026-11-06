<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\FactoryCount;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Gives `create()` / `make()` / `createQuietly()` / `lazy()` their single-or-collection answer by
 * returning the CONCRETE factory class, with its count, from every call that sets the count:
 * `count()`, `times()`, `forEachSequence()` and `new()` (here), and `Model::factory()` plus
 * `new UserFactory(3)` (see {@see ModelFactoryMethodTypeProvider} and the stub constructor).
 *
 * The Factory stub declares `TCount` (default `int|null`) and the conditional return
 * `TCount is null ? TModel : Collection<int, TModel>`. FactoryModelBindingHandler synthesizes the same
 * template on every user subclass, so `count(3)` on a `UserFactory` is `UserFactory<int<2, max>>`
 * and custom methods like `admin()` stay callable. "Some non-null count" is the plural sentinel
 * `int<2, max>`: Laravel returns a `Collection` for every non-null count (0 and 1 included), and a
 * literal sentinel trips a Psalm template rewrite bug (see decisions.md "Factories").
 *
 * Declines (null, so the stub's declared return stands) when the called method is not Laravel's own:
 * a user override of `count()` / `times()` / `new()` / `forEachSequence()` owns its return type.
 * A factory that overrides how the count is set, kept or built (see
 * {@see FactoryCount::runsLaravelCountPath()}) has no synthesized `TCount`, and `times()`
 * on it answers an unknown count. A class without a synthesized `TCount` (explicit `@extends Factory<M, X>`,
 * a user's own `@template TCount`) keeps the pre-#2188 `Factory<TModel, count>` answer from `count()` / `times()` / `forEachSequence()`.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/693
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/696
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/2188
 * @internal
 */
final class FactoryCountTypeProvider implements MethodReturnTypeProviderInterface
{
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
        if (!\in_array($methodName, FactoryCount::PRODUCERS, true)) {
            return null;
        }

        $source = $event->getSource();
        $codebase = $source->getCodebase();
        $calledClass = $event->getCalledFqClasslikeName() ?? $event->getFqClasslikeName();

        $declaring = $codebase->classlike_storage_provider->get($calledClass)->declaring_method_ids[$methodName] ?? null;
        if ($declaring === null || \strtolower($declaring->fq_class_name) !== \strtolower(Factory::class)) {
            return null;
        }

        // A factory that overrides how the count is set, kept or built owns its count: unknown, never narrowed.
        // That includes a descendant the receiver may hold, unless a literal `Class::times()` / `final` names the exact class.
        $lateBound = FactoryCount::isLateBound($event, $codebase, $calledClass);
        $countType = !FactoryCount::runsLaravelCountPath($codebase->classlike_storage_provider->get($calledClass), !$lateBound)
            ? FactoryCount::unknownCountUnion()
            : match ($methodName) {
                'new' => $lateBound ? FactoryCount::unknownCountUnion() : FactoryCount::singleCountUnion(),
                'foreachsequence' => FactoryCount::pluralCountUnion(),
                default => FactoryCount::resolveCountArgument($event->getCallArgs(), $source->getNodeTypeProvider()),
            };

        $concrete = FactoryCount::withCount($codebase, $calledClass, $countType, $event);
        if ($concrete instanceof Union || $methodName === 'new') {
            return $concrete;
        }

        // Only the base `Factory` and classes without a synthesized TCount reach here: `forEachSequence()` counts
        // the factory like `count()` does (`state(...)->count(count($sequence))`), so it takes the same fallback.
        $modelType = self::resolveModelType($event, $codebase);
        if (!$modelType instanceof Union) {
            return null;
        }

        return FactoryCount::baseFactory($modelType, $countType);
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
            if (FactoryCount::isModelType($modelType, $codebase)) {
                return $modelType;
            }
        }

        $calledClass = $event->getCalledFqClasslikeName() ?? $event->getFqClasslikeName();
        $resolved = FactoryCount::modelBoundBy($calledClass, $codebase);
        if ($resolved instanceof Union) {
            return $resolved;
        }

        return new Union([new TNamedObject(Model::class)]);
    }
}
