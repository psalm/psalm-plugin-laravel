<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Database\Eloquent\Model;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\FactoryCount;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\RelationResolver;
use Psalm\LaravelPlugin\Internal\ClassLineage;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Union;

/**
 * `has{Relation}()` / `for{Relation}()` served by Laravel's `Factory::__call()` (#2215), which forwards a name
 * starting with (case-sensitive) `has` / `for` to `has()` / `for()` on the model relation `Str::camel(substr($method, 3))`.
 *
 * A stub cannot name a method pattern and the answer depends on the receiver's `TModel`, so this is a return-type +
 * params pair: on the magic path Psalm asks the return-type provider first and, once it answers, needs the params
 * provider or `getMethodParams()` throws. Registered on `Factory` and on every descendant (providers dispatch on the
 * receiver's exact class). A call is claimed only when the class has no real method and no pseudo-method (`@method`,
 * boot macro) of that name, `__call` is Laravel's own, every receiver binds `TModel` to one model, and that model's
 * method is a relation ({@see RelationResolver::isRelationMethod()}). The answer is the receiver itself, at the unknown
 * count when a known descendant owns its count, as for Laravel's own `has()`. Rationale and known gaps: decisions.md.
 *
 * @internal
 */
final class FactoryRelationMethodProvider implements AfterCodebasePopulatedInterface
{
    /** @var array<string, Union> Process-invariant parameter types by their string form. */
    private static array $types = [];

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();
        $factoryLc = \strtolower(Factory::class);
        foreach ($codebase->classlike_storage_provider::getAll() as $lc => $storage) {
            if ($lc === $factoryLc || isset($storage->parent_classes[$factoryLc])) {
                $codebase->methods->return_type_provider->registerClosure($storage->name, self::getMethodReturnType(...));
                $codebase->methods->params_provider->registerClosure($storage->name, self::getMethodParams(...));
            }
        }
    }

    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $stmt = $event->getStmt();
        if (!$stmt instanceof MethodCall || !$stmt->name instanceof Identifier || !self::isForwarderName($stmt->name->name)) {
            return null;
        }

        $name = $event->getMethodNameLowercase();
        $class = $event->getFqClasslikeName();
        $codebase = $event->getSource()->getCodebase();
        $storage = $codebase->classlike_storage_provider->get($class);
        $receivers = self::receivers($event->getSource()->getNodeTypeProvider()->getType($stmt->var), $class);
        if ($receivers === []) {
            return null;
        }

        $model = self::model($codebase, $receivers);
        if ($model === null
            || !self::isForwarderSlot($codebase, $storage, $name)
            || !RelationResolver::isRelationMethod($codebase, $model, \str_replace(['_', '-'], '', \substr($name, 3)))
        ) {
            return null;
        }

        if (!FactoryCount::hasSynthesizedCount($storage) || FactoryCount::runsLaravelCountPath($storage)) {
            return new Union($receivers);
        }

        return FactoryCount::withCount($codebase, $class, FactoryCount::unknownCountUnion(), $event)
            ?? FactoryCount::baseFactory(new Union([new TNamedObject($model)]), FactoryCount::unknownCountUnion());
    }

    /**
     * Laravel's `has{X}($count = null, $state = [])` plus the all-arrays sequence tail (Laravel 13.2+), and
     * `for{X}($state = [])`; a `null` state is read as `[]`. The `callable` is plain: a typed one makes Psalm compare a
     * `Sequence` through its `__invoke()` (`@return mixed`) and report `MixedArgumentTypeCoercion`. Psalm checks an
     * unpacked argument against every remaining parameter, so any unpack takes `mixed ...$parameters`.
     *
     * @return list<FunctionLikeParameter>|null
     */
    public static function getMethodParams(MethodParamsProviderEvent $event): ?array
    {
        $name = $event->getMethodNameLowercase();
        $codebase = $event->getStatementsSource()?->getCodebase();
        if (!self::isForwarderName($name)
            || !$codebase instanceof \Psalm\Codebase
            || !self::isForwarderSlot($codebase, $codebase->classlike_storage_provider->get($event->getFqClasslikeName()), $name)
        ) {
            return null;
        }

        if (\in_array(true, \array_column($event->getCallArgs() ?? [], 'unpack'), true)) {
            return [self::param('parameters', 'mixed', variadic: true)];
        }

        $state = self::param('state', 'array<string, mixed>|callable|null|' . Sequence::class, Type::getEmptyArray());

        return \str_starts_with($name, 'for') ? [$state] : [
            self::param('count', 'int|null|array<string, mixed>|callable|' . Sequence::class, Type::getNull()),
            $state,
            self::param('sequence', 'array<string, mixed>', variadic: true),
        ];
    }

    /** Type strings are parsed once per process. */
    private static function param(string $name, string $type, ?Union $default = null, bool $variadic = false): FunctionLikeParameter
    {
        $union = self::$types[$type] ??= Type::parseString($type);

        return new FunctionLikeParameter($name, false, $union, null, is_optional: true, is_nullable: $union->isNullable(), is_variadic: $variadic, default_type: $default);
    }

    /**
     * Laravel's prefix test is case-sensitive on the spelled name: `HasLinks()` throws.
     *
     * @psalm-pure
     */
    private static function isForwarderName(string $name): bool
    {
        return \strlen($name) > 3 && (\str_starts_with($name, 'has') || \str_starts_with($name, 'for'));
    }

    /**
     * No real method or pseudo-method (own, trait or ancestor `@method`, boot macro) owns the name, and `__call` is
     * Laravel's own. Psalm resolves pseudo-methods only after asking the providers, so the check is ours.
     *
     * @psalm-capabilities read-props
     */
    private static function isForwarderSlot(Codebase $codebase, ClassLikeStorage $storage, string $name): bool
    {
        return !isset($storage->declaring_method_ids[$name])
            && !isset($storage->pseudo_methods[$name])
            && FactoryCount::isFactoryMethod($codebase, $storage, '__call');
    }

    /**
     * Every receiver atomic of the called class (Psalm asks once per union member and combines the answers).
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
     * The one model every receiver binds `TModel` to, or null (unbound, a template, a union, or a disagreement).
     *
     * @param non-empty-list<Atomic> $receivers
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
}
