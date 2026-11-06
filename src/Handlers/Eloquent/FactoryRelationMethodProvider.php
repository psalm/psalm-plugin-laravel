<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Eloquent\Model;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\FactoryCount;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\RelationResolver;
use Psalm\LaravelPlugin\Internal\ClassLineage;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodParamsProviderInterface;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\StatementsSource;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Union;

/**
 * `has{Relation}()` / `for{Relation}()` served by Laravel's `Factory::__call()` (#2215): `__call` checks the macro
 * registry and `trashed`, then forwards any name starting with (case-sensitive) `has` / `for` to `has()` / `for()`
 * on the model relation `Str::camel(substr($method, 3))`, and throws when the model has no such relation.
 *
 * A stub cannot express a method-name pattern and the answer depends on the receiver's `TModel`, so this is a
 * return-type + params provider pair. On the magic path Psalm asks the return-type provider BEFORE pseudo-methods
 * and, once it answers, checks the arguments through `getMethodParams()`, which throws unless a params provider
 * answers; so the params gate is a superset of the return gate. A `MethodExistenceProvider` is not used: it gets no
 * receiver (so it cannot tell `Factory<User>` from `Factory<Team>`) and would also make `methodExists()` true for
 * every other caller.
 *
 * Registered on `Factory` (a bare `Factory<User>` receiver) and on every `Factory` descendant: Psalm dispatches
 * providers on the receiver's exact class. A call is claimed only when all of these hold, else Psalm reports it as
 * before (`UndefinedMagicMethod` on a sealed factory):
 *  - an instance call spelled with a lowercase `has` / `for` prefix and a non-empty suffix;
 *  - the name is no real method (an implemented interface's included) and no pseudo-method Psalm would resolve (a
 *    user `@method` on the class, a trait, an interface or a mixin, or a boot-time macro from `MacroHandler`), and
 *    the class resolves `__call` to Laravel's own ({@see isForwarderSlot()});
 *  - every receiver atomic binds `TModel` to one and the same model class;
 *  - that model's method is a relation ({@see RelationResolver::isRelationMethod()}), of any kind;
 *  - unless the receiver is a literal construction (`new UserFactory()`, `UserFactory::new()`), no known
 *    descendant bound to that model (or to no single model) fails the same slot test: an `F<null>` receiver may hold
 *    that descendant, whose `hasLinks(): string` or own `__call` need not return a factory at all.
 *
 * The answer is the receiver itself, so `TCount`, `&static` and intersections ride along as for Laravel's own
 * `has()` / `for()`. Like those, it is the receiver at the unknown count when a known descendant owns its count
 * ({@see FactoryCount::runsLaravelCountPath()}).
 *
 * A forwarder still never passes the user-method body rule ({@see FactoryCount::keepsCount()}): a macro registered
 * after boot under a `has*` name is invisible here and may change the count.
 *
 * @internal
 */
final class FactoryRelationMethodProvider implements MethodReturnTypeProviderInterface, MethodParamsProviderInterface, AfterCodebasePopulatedInterface
{
    /** Process-invariant parameter types, built once. */
    private static ?Union $stateType = null;

    private static ?Union $hasCountType = null;

    private static ?Union $sequenceType = null;

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
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();
        $factoryLc = \strtolower(Factory::class);
        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            if (isset($storage->parent_classes[$factoryLc])) {
                $codebase->methods->return_type_provider->registerClosure($storage->name, self::getMethodReturnType(...));
                $codebase->methods->params_provider->registerClosure($storage->name, self::getMethodParams(...));
            }
        }
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $name = $event->getMethodNameLowercase();
        $stmt = $event->getStmt();
        if (!self::isForwarderName($name)
            || !$stmt instanceof MethodCall
            || !$stmt->name instanceof Identifier
            || !self::isForwarderName($stmt->name->name)
        ) {
            return null;
        }

        $class = $event->getFqClasslikeName();
        $receivers = self::receivers($event->getSource()->getNodeTypeProvider()->getType($stmt->var), $class);
        if ($receivers === []) {
            return null;
        }

        $codebase = $event->getSource()->getCodebase();
        $storage = self::storage($codebase, $class);
        if (!$storage instanceof ClassLikeStorage || !self::isForwarderSlot($codebase, $storage, $name)) {
            return null;
        }

        $model = self::model($codebase, $receivers);
        $relation = \str_replace(['_', '-'], '', \substr($name, 3));
        if ($model === null || $relation === '' || !RelationResolver::isRelationMethod($codebase, $model, $relation)) {
            return null;
        }

        // `new UserFactory()` / `UserFactory::new()` hold exactly that class, so no descendant can answer instead.
        $var = $stmt->var;
        /** @psalm-var string|null $literal */
        $literal = ($var instanceof New_ || $var instanceof StaticCall) && $var->class instanceof Name
            ? $var->class->getAttribute('resolvedName')
            : null;
        $method = $var instanceof StaticCall && $var->name instanceof Identifier ? \strtolower($var->name->name) : null;
        $constructed = $literal !== null && \strcasecmp($literal, $class) === 0
            && ($var instanceof New_ || (\in_array($method, ['new', 'times'], true) && FactoryCount::isFactoryMethod($codebase, $storage, $method)));
        $exact = $constructed && !FactoryCount::isLateBound($var, $codebase, $class);
        if (!$exact && !self::descendantsLeaveSlot($codebase, $storage, $name, $model)) {
            return null;
        }

        if (!FactoryCount::hasSynthesizedCount($storage) || FactoryCount::runsLaravelCountPath($storage, $exact)) {
            return new Union($receivers);
        }

        return FactoryCount::withCount($codebase, $class, FactoryCount::unknownCountUnion(), $event)
            ?? FactoryCount::baseFactory(new Union([new TNamedObject($model)]), FactoryCount::unknownCountUnion());
    }

    /**
     * Laravel's `has{Relation}(int|array|callable|Sequence $count = null, array|callable $state = [])`, plus the
     * all-arrays sequence form (`hasLinks([...], [...], [...])`, Laravel 13.2+; earlier versions ignore the extra
     * arrays), and `for{Relation}(array|callable $state = [])`. A `null` state is accepted: Laravel reads it through
     * `?? []`.
     *
     * The plain `callable` is deliberate: a typed `callable(array, Model|null): array` makes Psalm compare a
     * `Sequence` through its `__invoke()` (`@return mixed`) and report `MixedArgumentTypeCoercion` on a valid call,
     * as it already does for `state(new Sequence(...))`.
     *
     * An unpacked argument is checked by Psalm against EVERY remaining parameter (`hasLinks(...[2])` would meet the
     * array-only sequence tail), and an empty one (`...[]`) reports `NoValue` against any typed parameter. Spreading
     * an argument list is plain forwarding, so with any unpack the forwarder takes `mixed ...$parameters`.
     */
    #[\Override]
    public static function getMethodParams(MethodParamsProviderEvent $event): ?array
    {
        $name = $event->getMethodNameLowercase();
        $source = $event->getStatementsSource();
        if (!self::isForwarderName($name) || !$source instanceof StatementsSource) {
            return null;
        }

        $codebase = $source->getCodebase();
        $storage = self::storage($codebase, $event->getFqClasslikeName());
        if (!$storage instanceof ClassLikeStorage || !self::isForwarderSlot($codebase, $storage, $name)) {
            return null;
        }

        foreach ($event->getCallArgs() ?? [] as $arg) {
            if ($arg->unpack) {
                return [new FunctionLikeParameter('parameters', false, Type::getMixed(), null, is_optional: true, is_variadic: true)];
            }
        }

        self::$stateType ??= Type::parseString('array<string, mixed>|callable|' . Sequence::class . '|null');
        $state = new FunctionLikeParameter('state', false, self::$stateType, null, is_optional: true, is_nullable: true, default_type: Type::getEmptyArray());
        if (\str_starts_with($name, 'for')) {
            return [$state];
        }

        self::$hasCountType ??= Type::parseString('int|null|array<string, mixed>|callable|' . Sequence::class);

        self::$sequenceType ??= Type::parseString('array<string, mixed>');

        return [
            new FunctionLikeParameter('count', false, self::$hasCountType, null, is_optional: true, is_nullable: true, default_type: Type::getNull()),
            $state,
            new FunctionLikeParameter('sequence', false, self::$sequenceType, null, is_optional: true, is_variadic: true),
        ];
    }

    /**
     * Laravel's prefix test is `Str::startsWith($method, ['for', 'has'])`, case-sensitive on the spelled name:
     * `HasLinks()` throws. `has()`, `for()`, `hasAttached()` and `forEachSequence()` are real methods.
     *
     * @psalm-pure
     */
    private static function isForwarderName(string $name): bool
    {
        return \strlen($name) > 3 && (\str_starts_with($name, 'has') || \str_starts_with($name, 'for'));
    }

    /**
     * The name can only reach Laravel's `__call()` forwarder on this class: no method Psalm dispatches to first
     * ({@see leavesSlot()}), and `__call` is Laravel's own.
     *
     * @psalm-capabilities read-props
     */
    private static function isForwarderSlot(Codebase $codebase, ClassLikeStorage $storage, string $name): bool
    {
        return self::leavesSlot($codebase, $storage, $name) && FactoryCount::isFactoryMethod($codebase, $storage, '__call');
    }

    /**
     * No method Psalm would dispatch to instead owns the name. Psalm resolves pseudo-methods after the providers, so
     * this mirrors its private `MissingMethodCallHandler::findPseudoMethodAndClassStorages()`: the class's real
     * method and `pseudo_methods` (its own, a trait's and an ancestor's `@method`, which the populator copies together
     * with `declaring_pseudo_method_ids`, and the boot macros `MacroHandler` writes); an implemented interface's real
     * method (existing on an abstract class to Psalm) or `@method`; a named (or transitive) mixin's `@method`.
     *
     * @psalm-capabilities read-props
     */
    private static function leavesSlot(Codebase $codebase, ClassLikeStorage $storage, string $name): bool
    {
        if (isset($storage->declaring_method_ids[$name]) || isset($storage->pseudo_methods[$name])) {
            return false;
        }

        foreach (\array_keys($storage->class_implements) as $interface) {
            $interfaceStorage = self::storage($codebase, $interface);
            if (isset($interfaceStorage?->methods[$name]) || isset($interfaceStorage?->pseudo_methods[$name])) {
                return false;
            }
        }

        foreach ([...$storage->namedMixins, ...$storage->transitiveNamedMixins] as $mixin) {
            if (isset(self::storage($codebase, $mixin->value)?->pseudo_methods[$name])) {
                return false;
            }
        }

        return true;
    }

    /**
     * No known descendant the receiver may hold at runtime (one bound to the same model, or to no single model) fails
     * the slot test: it declares the name, or overrides `__call`.
     */
    private static function descendantsLeaveSlot(Codebase $codebase, ClassLikeStorage $storage, string $name, string $model): bool
    {
        foreach (FactoryCount::descendantsOf(\strtolower($storage->name)) as $descendant) {
            $descendantStorage = self::storage($codebase, $descendant);
            if ($descendantStorage instanceof ClassLikeStorage && self::isForwarderSlot($codebase, $descendantStorage, $name)) {
                continue;
            }

            $bound = self::model($codebase, [new TNamedObject($descendant)]);
            if ($bound === null || \strcasecmp($bound, $model) === 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every receiver atomic of the called class: on `UserFactory<null>|UserFactory<int<2, max>>` Psalm asks once per
     * member and combines the answers, so answering all members each time yields the union. A template receiver
     * (`T of UserFactory`) is kept as the template.
     *
     * @return list<Atomic>
     * @psalm-pure
     */
    private static function receivers(?Union $type, string $class): array
    {
        $matches = [];
        foreach ($type?->getAtomicTypes() ?? [] as $atomic) {
            $named = self::named($atomic);
            if ($named instanceof TNamedObject && \strcasecmp($named->value, $class) === 0) {
                $matches[] = $atomic;
            }
        }

        return $matches;
    }

    /** @psalm-pure */
    private static function named(Atomic $atomic): ?TNamedObject
    {
        $atomic = $atomic instanceof TTemplateParam && $atomic->as->isSingle() ? $atomic->as->getSingleAtomic() : $atomic;

        return $atomic instanceof TNamedObject ? $atomic : null;
    }

    /**
     * The one model every receiver binds `TModel` to (`Factory<User>`, a subclass bound through `@extends`, the
     * naming convention or an abstract generic base), or null: unbound, a template, a union of models, or receivers
     * that disagree.
     *
     * @param list<Atomic> $receivers
     * @psalm-capabilities read-props
     */
    private static function model(Codebase $codebase, array $receivers): ?string
    {
        $model = null;
        foreach ($receivers as $atomic) {
            $named = self::named($atomic);
            $bound = $named instanceof TNamedObject ? ClassLineage::projectedTemplate($codebase, $named, Factory::class, 'TModel') : null;
            $single = $bound instanceof Union && $bound->isSingle() ? $bound->getSingleAtomic() : null;
            if (!$single instanceof TNamedObject
                || !ClassLineage::isA($codebase, $single->value, Model::class)
                || ($model !== null && \strcasecmp($model, $single->value) !== 0)
            ) {
                return null;
            }

            $model = $single->value;
        }

        return $model;
    }

    /** @psalm-mutation-free */
    private static function storage(Codebase $codebase, string $class): ?ClassLikeStorage
    {
        try {
            return $codebase->classlike_storage_provider->get($class);
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return null;
        }
    }
}
