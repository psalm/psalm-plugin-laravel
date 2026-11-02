<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Psalm\Codebase;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\PropertyStorage;
use Psalm\Type\Atomic\TClassString;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TLiteralClassString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Resolves `Model::factory()`. Discovery follows Laravel's effective `newFactory()` override, static
 * `$factory`, `#[UseFactory]`, naming convention, then generic fallback order. Concrete factories are
 * returned only with a usable TModel binding, carrying the count the first argument sets
 * (`UserFactory<int<2, max>>` for `factory(3)`, see FactoryCountTypeProvider::resolveFactoryCountArgument());
 * otherwise `Factory<ModelFqcn, count>` preserves model typing through count/make chains.
 *
 * An explicit `@use HasFactory<XFactory>` binding is re-typed with the count too. A model that overrides
 * `factory()` itself is left to its own declaration.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/960
 * @see FactoryCountTypeProvider
 * @internal
 */
final class ModelFactoryMethodTypeProvider implements AfterCodebasePopulatedInterface, MethodReturnTypeProviderInterface
{
    /** Pre-lowercased Model FQCN for parent_classes lookups. */
    private const MODEL_FQCN_LOWERCASE = 'illuminate\\database\\eloquent\\model';

    /**
     * Cached Factory<ModelFqcn, count> Unions keyed by the model FQCN and count.
     * Bounded by the number of HasFactory models in the project (times the few count shapes).
     *
     * @var array<string, Union>
     */
    private static array $factoryUnionCache = [];

    /** @var array<lowercase-string, true> */
    private static array $explicitHasFactoryBindings = [];

    public static function reset(): void
    {
        self::$factoryUnionCache = [];
        self::$explicitHasFactoryBindings = [];
    }

    /**
     * Fill only omitted HasFactory arity after Psalm has populated its default
     * binding. Snapshot explicit uses first because bare Factory is intentional.
     */
    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $provider = $event->getCodebase()->classlike_storage_provider;
        if (!$provider->has(HasFactory::class)) {
            return;
        }

        $hasFactory = \strtolower(HasFactory::class);
        $templateCount = \count($provider->get(HasFactory::class)->template_types ?? []);

        foreach ($provider::getAll() as $storage) {
            if (!isset($storage->used_traits[$hasFactory])) {
                continue;
            }

            if (isset($storage->template_type_uses_count[$hasFactory])) {
                self::$explicitHasFactoryBindings[\strtolower($storage->name)] = true;
                continue;
            }

            $storage->template_type_uses_count[$hasFactory] = $templateCount;
        }
    }

    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [HasFactory::class];
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if ($event->getMethodNameLowercase() !== 'factory') {
            return null;
        }

        $modelFqcn = $event->getCalledFqClasslikeName() ?? $event->getFqClasslikeName();
        $codebase = $event->getSource()->getCodebase();

        // has() pre-check avoids constructing an InvalidArgumentException for
        // classes Psalm has not scanned.
        if (!$codebase->classlike_storage_provider->has($modelFqcn)) {
            return null;
        }

        $storage = $codebase->classlike_storage_provider->get($modelFqcn);

        // Only fire for Model subclasses. Skip non-Model HasFactory hosts.
        if (!isset($storage->parent_classes[self::MODEL_FQCN_LOWERCASE])) {
            return null;
        }

        // A model-level `factory()` override owns its return type and count semantics.
        $declaringId = $storage->declaring_method_ids['factory'] ?? null;
        if ($declaringId === null || \strtolower($declaringId->fq_class_name) !== \strtolower(HasFactory::class)) {
            return null;
        }

        // `static::factory()` / `$class::factory()` on a non-final model may run a subclass's own
        // `factory()`, so the argument no longer decides the count.
        $count = FactoryCountTypeProvider::isLateBound($event, $codebase, $modelFqcn)
            ? FactoryCountTypeProvider::unknownCountUnion()
            : FactoryCountTypeProvider::resolveFactoryCountArgument($event->getCallArgs(), $event->getSource()->getNodeTypeProvider());

        // An explicit `@use HasFactory<XFactory>` binding: the stub returns TFactory (the user-chosen
        // class), which this handler re-types with the count. Anything but a plain class stays as declared.
        if (self::hasUserBoundTFactory($storage, $codebase)) {
            $boundFactory = self::boundTFactoryClass($storage);
            if ($boundFactory === null) {
                return null;
            }

            $gated = self::gate($codebase, $boundFactory, $count);

            return FactoryCountTypeProvider::withCount($codebase, $boundFactory, $gated)
                ?? self::unownedCount($codebase, $boundFactory, $gated, $modelFqcn);
        }

        $factoryClass = self::discoverFactoryClass($modelFqcn, $storage, $codebase);
        if ($factoryClass !== null && self::isUsableFactoryClass($factoryClass, $codebase)) {
            $gated = self::gate($codebase, $factoryClass, $count);

            return FactoryCountTypeProvider::withCount($codebase, $factoryClass, $gated)
                ?? self::unownedCount($codebase, $factoryClass, $gated, $modelFqcn)
                ?? new Union([new TNamedObject($factoryClass)]);
        }

        // The factory class is unproven here, so its count semantics are too: `factory(3)` counts the factory (an
        // array or callable is a state) and any argument leaves the count unknown. Claiming `null` would type
        // `make()` as a single model where Laravel returns a Collection.
        $fallbackCount = $event->getCallArgs() === [] ? $count : FactoryCountTypeProvider::unknownCountUnion();

        return self::$factoryUnionCache[$modelFqcn . ':' . $fallbackCount->getId()] ??= new Union([
            new TGenericObject(Factory::class, [new Union([new TNamedObject($modelFqcn)]), $fallbackCount]),
        ]);
    }

    /**
     * The factory `factory()` returns may be any subclass of its declared class, so the count answers only when the
     * class and every descendant run Laravel's own count path ({@see FactoryUserMethodCountProvider::runsLaravelCountPath()}).
     */
    private static function gate(Codebase $codebase, string $factoryClass, Union $count): Union
    {
        $provider = $codebase->classlike_storage_provider;

        return $provider->has($factoryClass) && !FactoryUserMethodCountProvider::runsLaravelCountPath($provider->get($factoryClass))
            ? FactoryCountTypeProvider::unknownCountUnion()
            : $count;
    }

    /**
     * A factory that owns no synthesized `TCount` (explicit `@extends Factory<M, null>` / `<M, int>`, a user's own
     * `TCount`) still counts through `Model::factory(3)` at runtime, so declining to its declared class would type
     * `make()` as a single model where Laravel returns a Collection. When the first argument may be a count the
     * answer is the base `Factory<TModel, count>`, as `count()` gives; unknown classification is the unknown count.
     *
     * Declines (null) for a definitely-null count (`factory()`, a state array or closure), a class that is not a
     * scanned `Factory`, and a class whose effective `TCount` already is the default `int|null` (a class that declares
     * a Laravel method, kept as the concrete class so its custom methods stay callable: the default already
     * reads the sound union).
     */
    private static function unownedCount(Codebase $codebase, string $factoryClass, Union $count, string $modelFqcn): ?Union
    {
        $provider = $codebase->classlike_storage_provider;
        if ($count->isNull() || !$provider->has($factoryClass)) {
            return null;
        }

        $storage = $provider->get($factoryClass);
        if (!isset($storage->parent_classes[\strtolower(Factory::class)])) {
            return null;
        }

        $declared = $storage->template_extended_params[Factory::class]['TCount'] ?? null;
        if ($declared instanceof Union && $declared->getId() === 'int|null') {
            return null;
        }

        return new Union([
            new TGenericObject(Factory::class, [
                FactoryCountTypeProvider::resolveModelFromClass($factoryClass, $codebase) ?? new Union([new TNamedObject($modelFqcn)]),
                $count,
            ]),
        ]);
    }

    /**
     * The plain factory class an explicit `@use HasFactory<XFactory>` names. A generic argument
     * (`HasFactory<Factory<User, null>>`) is the user's own count contract and is not re-typed.
     *
     * @psalm-mutation-free
     */
    private static function boundTFactoryClass(ClassLikeStorage $storage): ?string
    {
        $bound = $storage->template_extended_params[HasFactory::class]['TFactory'] ?? null;
        if (!$bound instanceof Union || !$bound->isSingle()) {
            return null;
        }

        $atomic = $bound->getSingleAtomic();

        return $atomic instanceof TNamedObject && !$atomic instanceof TGenericObject ? $atomic->value : null;
    }

    /**
     * Mirrors Laravel's runtime resolution order without invoking model code.
     * A present but statically ambiguous custom method, property, or attribute
     * stops discovery because Laravel would not continue to a later tier.
     */
    private static function discoverFactoryClass(
        string $modelFqcn,
        ClassLikeStorage $storage,
        \Psalm\Codebase $codebase,
    ): ?string {
        [$hasCustomMethod, $fromCustomMethod] = self::factoryFromCustomNewFactory($storage, $codebase);
        if ($hasCustomMethod) {
            return $fromCustomMethod;
        }

        [$hasFactoryProperty, $fromFactoryProperty] = self::factoryFromStaticProperty($storage, $codebase);
        if ($hasFactoryProperty) {
            return $fromFactoryProperty;
        }

        [$hasAttribute, $fromAttribute] = self::factoryFromUseFactoryAttribute($storage);
        if ($hasAttribute) {
            return $fromAttribute;
        }

        /** @var class-string<Model> $modelFqcn */
        try {
            $resolved = Factory::resolveFactoryName($modelFqcn);
        } catch (\Throwable) {
            return null;
        }

        return $codebase->classlike_storage_provider->has($resolved) ? $resolved : null;
    }

    /**
     * @return array{bool, ?string}
     *
     * @psalm-mutation-free
     */
    private static function factoryFromCustomNewFactory(
        ClassLikeStorage $storage,
        \Psalm\Codebase $codebase,
    ): array {
        $declaringId = $storage->declaring_method_ids['newfactory'] ?? null;
        if ($declaringId === null
            || \strtolower($declaringId->fq_class_name) === \strtolower(HasFactory::class)
        ) {
            return [false, null];
        }

        try {
            $methodStorage = $codebase->methods->getStorage($declaringId);
        } catch (\UnexpectedValueException) {
            return [true, null];
        }

        foreach ([$methodStorage->return_type, $methodStorage->signature_return_type] as $returnType) {
            $factoryClass = self::factoryClassFromObjectType($returnType);
            if ($factoryClass !== null && self::isUsableFactoryClass($factoryClass, $codebase)) {
                return [true, $factoryClass];
            }
        }

        return [true, null];
    }

    /**
     * @return array{bool, ?string}
     *
     * @psalm-mutation-free
     */
    private static function factoryFromStaticProperty(
        ClassLikeStorage $storage,
        \Psalm\Codebase $codebase,
    ): array {
        $declaringClass = $storage->declaring_property_ids['factory'] ?? null;
        if ($declaringClass === null) {
            return [false, null];
        }

        if (!$codebase->classlike_storage_provider->has($declaringClass)) {
            return [true, null];
        }

        $property = $codebase->classlike_storage_provider
            ->get($declaringClass)
            ->properties['factory'] ?? null;
        if (!$property instanceof PropertyStorage || $property->is_static !== true) {
            return [true, null];
        }

        return [true, self::factoryClassFromClassString($property->type ?? $property->suggested_type)];
    }

    /**
     * @psalm-pure
     */
    private static function factoryClassFromObjectType(?Union $type): ?string
    {
        if (!$type instanceof \Psalm\Type\Union || \count($type->getAtomicTypes()) !== 1) {
            return null;
        }

        $atomic = \array_values($type->getAtomicTypes())[0];

        return $atomic instanceof TNamedObject ? $atomic->value : null;
    }

    /**
     * @psalm-pure
     */
    private static function factoryClassFromClassString(?Union $type): ?string
    {
        if (!$type instanceof \Psalm\Type\Union || \count($type->getAtomicTypes()) !== 1) {
            return null;
        }

        $atomic = \array_values($type->getAtomicTypes())[0];
        if ($atomic instanceof TLiteralClassString) {
            return $atomic->value;
        }

        return $atomic instanceof TClassString && $atomic->as_type instanceof \Psalm\Type\Atomic\TNamedObject
            ? $atomic->as_type->value
            : null;
    }

    /** @psalm-mutation-free */
    private static function isUsableFactoryClass(string $factoryClass, \Psalm\Codebase $codebase): bool
    {
        return self::isConcreteFactoryClass($factoryClass, $codebase)
            && self::hasModelTemplateBinding($factoryClass, $codebase);
    }

    /** @psalm-mutation-free */
    private static function isConcreteFactoryClass(string $factoryClass, \Psalm\Codebase $codebase): bool
    {
        if (!$codebase->classlike_storage_provider->has($factoryClass)) {
            return false;
        }

        $storage = $codebase->classlike_storage_provider->get($factoryClass);

        return !$storage->abstract
            && !$storage->is_interface
            && !$storage->is_trait
            && isset($storage->parent_classes[\strtolower(Factory::class)]);
    }

    /**
     * A usable TModel is one exact, scanned Model subclass. Bare Model and
     * ambiguous bindings would erase the concrete model from factory chains.
     *
     * @psalm-mutation-free
     */
    private static function hasModelTemplateBinding(string $factoryClass, \Psalm\Codebase $codebase): bool
    {
        $storage = $codebase->classlike_storage_provider->get($factoryClass);
        $tModel = $storage->template_extended_params[Factory::class]['TModel'] ?? null;
        if (!$tModel instanceof Union || \count($tModel->getAtomicTypes()) !== 1) {
            return false;
        }

        $atomic = \array_values($tModel->getAtomicTypes())[0];
        if (!$atomic instanceof TNamedObject
            || $atomic->value === Model::class
            || !$codebase->classlike_storage_provider->has($atomic->value)
        ) {
            return false;
        }

        $modelStorage = $codebase->classlike_storage_provider->get($atomic->value);

        return isset($modelStorage->parent_classes[self::MODEL_FQCN_LOWERCASE]);
    }

    /**
     * @return array{bool, ?string}
     *
     * @psalm-mutation-free
     */
    private static function factoryFromUseFactoryAttribute(ClassLikeStorage $storage): array
    {
        foreach ($storage->attributes as $attribute) {
            if ($attribute->fq_class_name !== UseFactory::class) {
                continue;
            }

            $argType = $attribute->args[0]->type ?? null;

            return [true, $argType instanceof Union
                ? self::factoryClassFromClassString($argType)
                : null];
        }

        return [false, null];
    }

    /**
     * True when the model, an ancestor, or a composed application trait
     * supplied an explicit @use binding. The signal is captured before omitted
     * uses are normalized to the trait's populated default arity.
     */
    private static function hasUserBoundTFactory(
        ClassLikeStorage $storage,
        \Psalm\Codebase $codebase,
    ): bool {
        $relatedClasslikes = [\strtolower($storage->name) => true];
        foreach ($storage->parent_classes as $parentClassLowercase => $_) {
            $relatedClasslikes[$parentClassLowercase] = true;
        }

        $visited = [];
        while (($classlike = \array_key_first($relatedClasslikes)) !== null) {
            unset($relatedClasslikes[$classlike]);
            if (isset($visited[$classlike])) {
                continue;
            }

            $visited[$classlike] = true;
            if (isset(self::$explicitHasFactoryBindings[$classlike])) {
                return true;
            }

            if (!$codebase->classlike_storage_provider->has($classlike)) {
                continue;
            }

            foreach ($codebase->classlike_storage_provider->get($classlike)->used_traits as $traitLowercase => $_) {
                $relatedClasslikes[$traitLowercase] = true;
            }
        }

        return false;
    }
}
