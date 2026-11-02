<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Internal\ClassLineage;
use Psalm\NodeTypeProvider;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TBool;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TFloat;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TIntRange;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TNumeric;
use Psalm\Type\Atomic\TNumericString;
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
 * {@see FactoryUserMethodCountProvider::runsLaravelCountPath()}) has no synthesized `TCount`, and `times()`
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
     * The `Factory` methods that set the count and answer here; every other method is answered by
     * {@see FactoryUserMethodCountProvider}.
     *
     * @var list<lowercase-string>
     */
    public const PRODUCERS = ['count', 'times', 'foreachsequence', 'new'];

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
        if (!\in_array($methodName, self::PRODUCERS, true)) {
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
        $lateBound = self::isLateBound($event, $codebase, $calledClass);
        $countType = !FactoryUserMethodCountProvider::runsLaravelCountPath($codebase->classlike_storage_provider->get($calledClass), !$lateBound)
            ? self::unknownCountUnion()
            : match ($methodName) {
                'new' => $lateBound ? self::unknownCountUnion() : self::singleCountUnion(),
                'foreachsequence' => self::pluralCountUnion(),
                default => self::resolveCountArgument($event->getCallArgs(), $source->getNodeTypeProvider()),
            };

        $concrete = self::withCount($codebase, $calledClass, $countType, $event);
        if ($concrete instanceof Union || $methodName === 'new') {
            return $concrete;
        }

        // Only the base `Factory` and classes without a synthesized TCount reach here: `forEachSequence()` counts
        // the factory like `count()` does (`state(...)->count(count($sequence))`), so it takes the same fallback.
        $modelType = self::resolveModelType($event, $codebase);
        if (!$modelType instanceof Union) {
            return null;
        }

        return new Union([
            new TGenericObject(Factory::class, [$modelType, $countType]),
        ]);
    }

    /**
     * The factory class carrying `$count`, or null when the class does not own a plugin-synthesized `TCount`
     * (explicit `@extends Factory<M, X>`, a user's own `@template TCount`, an overridden count method) or has
     * other template slots the receiver cannot supply. Every other slot is taken from the receiver's own
     * template arguments by position in the CLASS's template list, with TCount located by name, never by position.
     * Only the factory atomic's count is widened: the receiver's late-static marker (`&static`) and every other
     * intersection member (`F<null>&Marker`) are carried over.
     */
    public static function withCount(Codebase $codebase, string $factoryClass, Union $count, ?MethodReturnTypeProviderEvent $event = null): ?Union
    {
        if (!$codebase->classlike_storage_provider->has($factoryClass)) {
            return null;
        }

        $storage = $codebase->classlike_storage_provider->get($factoryClass);
        if (!FactoryModelBindingHandler::hasSynthesizedCount($storage)) {
            return null;
        }

        $receiver = $event instanceof MethodReturnTypeProviderEvent ? self::receiver($event, $storage->name) : null;
        $receiverParams = $receiver instanceof TGenericObject ? $receiver->type_params : $event?->getTemplateTypeParameters();
        $slots = \array_keys($storage->template_types ?? []);

        /** @var non-empty-list<Union> $params */
        $params = [];
        foreach ($slots as $position => $slot) {
            $param = $slot === 'TCount' ? $count : (\count($receiverParams ?? []) === \count($slots) ? $receiverParams[$position] ?? null : null);
            if (!$param instanceof Union) {
                return null;
            }

            $params[] = $param;
        }

        return new Union([new TGenericObject($storage->name, $params, false, $receiver?->is_static ?? false, $receiver?->extra_types ?? [])]);
    }

    /**
     * The receiver of the call when it is a single object of `$class` (`$this->count(3)` in a `static`-returning
     * method has `&static`, or the declared `static` return is reported as more specific than what was inferred),
     * or the `static` keyword of `static::times(3)`. Null for a union, a template or a class-string receiver.
     */
    public static function receiver(MethodReturnTypeProviderEvent $event, string $class): ?TNamedObject
    {
        $stmt = $event->getStmt();
        if ($stmt instanceof StaticCall) {
            return $stmt->class instanceof Name && \strtolower($stmt->class->name) === 'static' ? new TNamedObject($class, true) : null;
        }

        $type = $event->getSource()->getNodeTypeProvider()->getType($stmt->var);
        $atomic = $type instanceof Union && $type->isSingle() ? $type->getSingleAtomic() : null;

        return $atomic instanceof TNamedObject && \strtolower($atomic->value) === \strtolower($class) ? $atomic : null;
    }

    /**
     * Laravel's `HasFactory::factory($count, $state)` counts the factory with `is_numeric($count) ? $count : null`:
     * int/float/numeric-string (0 and 1 included) is plural, null/array/callable/bool is a state or no count.
     * The count is the first positional or the `count:` argument; any unpack, or a name other than
     * `count:` / `state:`, leaves it unknown.
     *
     * @param list<\PhpParser\Node\Arg> $callArgs
     */
    public static function resolveFactoryCountArgument(array $callArgs, NodeTypeProvider $nodeTypeProvider): Union
    {
        $countArg = null;
        foreach ($callArgs as $index => $arg) {
            $name = $arg->name?->name;
            if ($arg->unpack || (!in_array($name, [null, 'count', 'state'], true))) {
                return self::unknownCountUnion();
            }

            if ($name === 'count' || ($name === null && $index === 0)) {
                $countArg = $arg;
            }
        }

        if ($countArg === null) {
            return self::singleCountUnion();
        }

        $argType = $nodeTypeProvider->getType($countArg->value);
        if (!$argType instanceof Union) {
            return self::unknownCountUnion();
        }

        $isPlural = null;
        foreach ($argType->getAtomicTypes() as $atomic) {
            $atomicPlural = match (true) {
                $atomic instanceof TInt, $atomic instanceof TFloat, $atomic instanceof TNumericString, $atomic instanceof TNumeric => true,
                $atomic instanceof TLiteralString => \is_numeric($atomic->value),
                $atomic instanceof TNull, $atomic instanceof TArray, $atomic instanceof TKeyedArray, $atomic instanceof TBool,
                $atomic instanceof TCallable, $atomic instanceof TClosure => false,
                default => null,
            };

            if ($atomicPlural === null || ($isPlural !== null && $isPlural !== $atomicPlural)) {
                return self::unknownCountUnion();
            }

            $isPlural = $atomicPlural;
        }

        return $isPlural ? self::pluralCountUnion() : self::singleCountUnion();
    }

    /**
     * `static::new()`, `$class::new()` or an instance receiver may name a subclass that overrides the
     * method and presets a count, which `final` rules out. Callers then answer "count unknown".
     *
     * @psalm-mutation-free
     */
    public static function isLateBound(MethodReturnTypeProviderEvent $event, Codebase $codebase, string $class): bool
    {
        $stmt = $event->getStmt();
        $literalClass = $stmt instanceof StaticCall
            && $stmt->class instanceof Name
            && !\in_array(\strtolower($stmt->class->name), ['static', 'self', 'parent'], true);

        return !$literalClass && !$codebase->classlike_storage_provider->get($class)->final;
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
     * Every atomic is Model or a Model subclass. Lineage from Psalm's storage ({@see ClassLineage}):
     * PHPT fixture models declared inline are scanned but not autoloadable.
     *
     * @psalm-mutation-free
     */
    private static function isModelType(?Union $type, Codebase $codebase): bool
    {
        if (!$type instanceof Union) {
            return false;
        }

        foreach ($type->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject || !ClassLineage::isA($codebase, $atomic->value, Model::class)) {
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
    public static function resolveModelFromClass(string $factoryClass, Codebase $codebase): ?Union
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
     * Map the count argument of `count()` / `times()` to the TCount template type:
     *  - missing or `null` literal → `null` (single-mode branch)
     *  - any non-null int (literal `0`/`1`/`N`, plain `int`, `int<a, b>`,
     *    `positive-int`, etc.) → `int<2, max>` (collection branch, Laravel's
     *    `Factory::make()` returns a collection for every non-null count)
     *  - `int|null`, an unpack, or any other atomic → `null|int<2, max>` (forces the
     *    conditional to a union of both branches)
     *
     * `int<2, max>` is just a representative non-null value, standing for "a count".
     *
     * @param list<\PhpParser\Node\Arg> $callArgs
     */
    private static function resolveCountArgument(array $callArgs, NodeTypeProvider $nodeTypeProvider): Union
    {
        $arg = $callArgs[0] ?? null;
        if ($arg === null) {
            return self::singleCountUnion();
        }

        $argType = $arg->unpack ? null : $nodeTypeProvider->getType($arg->value);
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

    private static function singleCountUnion(): Union
    {
        return self::$singleCountUnion ??= new Union([new TNull()]);
    }

    private static function pluralCountUnion(): Union
    {
        return self::$pluralCountUnion ??= new Union([new TIntRange(2, null)]);
    }

    public static function unknownCountUnion(): Union
    {
        return self::$unknownCountUnion ??= new Union([new TNull(), new TIntRange(2, null)]);
    }
}
