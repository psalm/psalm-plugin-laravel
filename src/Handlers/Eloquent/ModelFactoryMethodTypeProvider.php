<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TLiteralClassString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Union;

/**
 * Resolves `Model::factory()` to a useful factory type when the model uses
 * `HasFactory` without an explicit `@use HasFactory<XFactory>` template arg.
 *
 * Discovery order mirrors Laravel's runtime in `HasFactory::newFactory()`:
 *   1. `#[UseFactory(XFactory::class)]` attribute on the model class.
 *   2. `Factory::resolveFactoryName($modelFqcn)` — Laravel naming convention,
 *      e.g. `App\Models\Bookshelf` → `Database\Factories\BookshelfFactory`.
 *      Mirrors Larastan's lookup at
 *      `ModelFactoryDynamicStaticMethodReturnTypeExtension::getFactoryReflection()`.
 *   3. Fallback to `Factory<modelFqcn, null>` so the downstream chain still
 *      has `TModel` and `TCount` bound and `count(N)->make()` resolves to a
 *      `Collection<int, modelFqcn>` (the bug from #960).
 *
 * Returning the concrete `XFactory` (when discovered) is strictly more useful
 * than the generic `Factory<modelFqcn, null>`: subclass-specific state methods
 * (`forUser()`, `withOwner()`, etc.) become callable on the chain without
 * requiring `@use HasFactory<XFactory>` on every model.
 *
 * Skipped paths (deferred to the stub):
 *   - When the user wrote an explicit `@use HasFactory<XFactory>` binding, the
 *     stub's `@return TFactory` already resolves to the user's choice (the
 *     documented escape hatch).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/960
 * @see FactoryCountTypeProvider
 * @internal
 */
final class ModelFactoryMethodTypeProvider implements MethodReturnTypeProviderInterface
{
    /** Pre-lowercased Model FQCN for parent_classes lookups. */
    private const MODEL_FQCN_LOWERCASE = 'illuminate\\database\\eloquent\\model';

    /**
     * Cached Factory<ModelFqcn, null> Unions keyed by the model FQCN.
     * Bounded by the number of HasFactory models in the project.
     *
     * @var array<string, Union>
     */
    private static array $factoryUnionCache = [];

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

        // Defer to the stub when the user wrote an explicit
        // `@use HasFactory<XFactory>` binding. The stub returns TFactory which
        // resolves to the user-chosen Factory subclass — strictly more precise
        // than anything this handler can produce.
        if (self::hasUserBoundTFactory($storage)) {
            return null;
        }

        // Tier 1 + 2: discover the concrete factory class. Return it only
        // when its class storage carries an `@extends Factory<X>` binding,
        // because `FactoryCountTypeProvider::resolveModelFromClass()` needs
        // that binding to recover TModel from a chain like
        // `MyFactory::count(N)->make()`. Real-world factories often skip the
        // `@extends` docblock (BookStack, etc.) and rely on the runtime
        // `protected $model = X::class` property; returning the bare class
        // in that case would erase TModel and downgrade the chain to base
        // `Model`. The next-best result (`Factory<modelFqcn, null>`) is
        // strictly more useful in that scenario.
        $factoryClass = self::discoverFactoryClass($modelFqcn, $storage, $codebase);
        if ($factoryClass !== null && self::hasModelTemplateBinding($factoryClass, $codebase)) {
            return new Union([new TNamedObject($factoryClass)]);
        }

        // Tier 3 fallback: Factory<modelFqcn, null>. Keeps TModel and TCount
        // bound so count(N)->make() still resolves to Collection<int,
        // modelFqcn>. Cached because the value depends only on $modelFqcn.
        return self::$factoryUnionCache[$modelFqcn] ??= new Union([
            new TGenericObject(Factory::class, [
                new Union([new TNamedObject($modelFqcn)]),
                new Union([new TNull()]),
            ]),
        ]);
    }

    /**
     * Tier 1: `#[UseFactory(XFactory::class)]` attribute on the model.
     * Tier 2: `Factory::resolveFactoryName()` naming convention.
     * Returns the FQCN only if Psalm has scanned it; otherwise null so the
     * caller can fall through to the generic `Factory<modelFqcn, null>`.
     *
     * Not mutation-free: `Factory::resolveFactoryName()` reads Laravel
     * container state (`appNamespace()`). The handler context tolerates
     * impure helpers; the caller is the only entry point and is also impure.
     */
    private static function discoverFactoryClass(
        string $modelFqcn,
        ClassLikeStorage $storage,
        \Psalm\Codebase $codebase
    ): ?string {
        $fromAttribute = self::factoryFromUseFactoryAttribute($storage);
        if ($fromAttribute !== null && $codebase->classlike_storage_provider->has($fromAttribute)) {
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
     * Confirms the factory class carries an explicit `@extends Factory<X>` to
     * a Model SUBCLASS (not bare `Model`). Psalm populates
     * `template_extended_params[Factory::class]['TModel']` with the bound
     * default (bare `Model`) when the user omits `@extends`, so a plain
     * `isset()` would be too permissive — we'd return the concrete factory
     * class and FactoryCountTypeProvider would later recover TModel as
     * `Model`, downgrading the chain.
     *
     * @psalm-mutation-free
     */
    private static function hasModelTemplateBinding(string $factoryClass, \Psalm\Codebase $codebase): bool
    {
        try {
            $storage = $codebase->classlike_storage_provider->get($factoryClass);
        } catch (\InvalidArgumentException) {
            return false;
        }

        $tModel = $storage->template_extended_params[Factory::class]['TModel'] ?? null;
        if (!$tModel instanceof Union) {
            return false;
        }

        foreach ($tModel->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNamedObject && $atomic->value !== Model::class) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read `#[UseFactory(XFactory::class)]` from the model class's storage.
     * Mirrors `HasFactory::getUseFactoryAttribute()` at static-analysis time.
     *
     * @psalm-mutation-free
     */
    private static function factoryFromUseFactoryAttribute(ClassLikeStorage $storage): ?string
    {
        foreach ($storage->attributes as $attribute) {
            if ($attribute->fq_class_name !== UseFactory::class) {
                continue;
            }

            $firstArg = $attribute->args[0] ?? null;
            if ($firstArg === null) {
                continue;
            }

            $argType = $firstArg->type;
            if (!$argType instanceof Union) {
                continue;
            }

            foreach ($argType->getAtomicTypes() as $atomic) {
                if ($atomic instanceof TLiteralClassString) {
                    return $atomic->value;
                }
            }
        }

        return null;
    }

    /**
     * True when the model carries an explicit HasFactory template binding to
     * a Factory subclass. Psalm's populator copies user-supplied offsets into
     * `template_extended_params[HasFactory::class]['TFactory']`; the unbound
     * default fills the same slot with the bound (bare Factory), so a value
     * other than bare Factory signals a user binding.
     *
     * @psalm-mutation-free
     */
    private static function hasUserBoundTFactory(ClassLikeStorage $storage): bool
    {
        $binding = $storage->template_extended_params[HasFactory::class]['TFactory'] ?? null;
        if (!$binding instanceof Union) {
            return false;
        }

        foreach ($binding->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNamedObject && $atomic->value !== Factory::class) {
                return true;
            }
        }

        return false;
    }
}
