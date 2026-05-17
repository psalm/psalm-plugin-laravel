<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Union;

/**
 * Binds Model::factory() to Factory<static, null> for every model that uses
 * the HasFactory trait without an explicit template argument.
 *
 * HasFactory::factory() declares @return TFactory with @template TFactory of
 * Factory. Models that omit the template argument (the framework default)
 * collapse TFactory to the bound Factory, losing the TModel binding the
 * downstream chain needs: FactoryCountTypeProvider cannot recover TModel
 * from a bare Factory, and the create()/make() conditional return picks the
 * single-model branch because the receiver's TCount is unbound.
 *
 * Skipped paths (deferred to the stub or noted as limitations):
 *   - When the user wrote an explicit @use HasFactory<XFactory> binding,
 *     XFactory is strictly more precise than Factory<Model> because it
 *     preserves subclass-specific state methods. Return null in that case
 *     so the stub's @return TFactory flows through.
 *   - Model::factory($count) with a numeric first arg should resolve to
 *     Collection (Laravel forwards $count to ->count() internally). Not
 *     handled here; users writing factory(3)->make() get Factory<Model>
 *     and need to switch to factory()->count(3)->make() for the precise
 *     Collection type. See issue #960 for the broader chain coverage.
 *
 * Dispatch note: Psalm's MethodReturnTypeProvider lookup falls back to the
 * declaring_method_id when the called class has no registered hook. For a
 * trait method invoked via the using class (Article::factory()), the
 * declaring class is the trait itself, and Psalm passes the actual called
 * class through $event->getCalledFqClasslikeName(). See vendor/vimeo/psalm
 * MethodCallReturnTypeFetcher.php and ExistingAtomicStaticCallAnalyzer.php
 * for the dispatch sequence.
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
        // @use HasFactory<XFactory> binding. The stub returns TFactory which
        // resolves to the user-chosen Factory subclass, strictly more
        // precise than Factory<Model>.
        if (self::hasUserBoundTFactory($storage)) {
            return null;
        }

        // Emit both template params explicitly: Factory<Model, null>. Psalm 7
        // does not reliably substitute the TCount default from a 1-arg
        // TGenericObject, so an unbound TCount leaks into make()/create() and
        // forces a union of both conditional branches.
        return self::$factoryUnionCache[$modelFqcn] ??= new Union([
            new TGenericObject(Factory::class, [
                new Union([new TNamedObject($modelFqcn)]),
                new Union([new TNull()]),
            ]),
        ]);
    }

    /**
     * True when the model carries an explicit HasFactory template binding to
     * a Factory subclass. Psalm's populator copies user-supplied offsets into
     * template_extended_params[HasFactory::class]['TFactory']; the unbound
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
