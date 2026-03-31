<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Attributes\CollectedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Util\ModelPropertyResolver;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Storage\AttributeArg;
use Psalm\Type;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TLiteralClassString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Narrows collection return types for models using custom Eloquent collections.
 *
 * When a model declares a custom collection via `#[CollectedBy(UserCollection::class)]`
 * or overrides `newCollection()` with a narrowed return type, Builder methods like
 * `get()`, `findMany()`, and `Model::all()` should return the custom collection type
 * instead of `Illuminate\Database\Eloquent\Collection`.
 *
 * Detection order (first match wins):
 * 1. `#[CollectedBy]` attribute on the model class (preferred, modern approach)
 * 2. `newCollection()` return type narrowed to a concrete Collection subclass
 *
 * @see https://laravel.com/docs/master/eloquent-collections#custom-collections
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/622
 * @internal
 */
final class CustomCollectionHandler implements MethodReturnTypeProviderInterface
{
    /**
     * Cache: model FQCN → custom collection FQCN (or null if none).
     *
     * @var array<string, class-string|null>
     */
    private static array $cache = [];

    /**
     * Builder methods that return `Eloquent\Collection<int, TModel>` and should
     * be narrowed to the custom collection type.
     *
     * @var list<string>
     */
    private const COLLECTION_METHODS = [
        'get',
        'findmany',
        'hydrate',
        'fromquery',
    ];

    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [Builder::class];
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if (!\in_array($event->getMethodNameLowercase(), self::COLLECTION_METHODS, true)) {
            return null;
        }

        $templateTypeParameters = $event->getTemplateTypeParameters();

        // Builder<TModel> — TModel is template param at index 0
        $modelClass = ModelPropertyResolver::extractModelFromUnion($templateTypeParameters[0] ?? null);
        if ($modelClass === null) {
            return null;
        }

        $codebase = $event->getSource()->getCodebase();

        return self::buildCustomCollectionType($codebase, $modelClass);
    }

    /**
     * Handle Model::all() for concrete model classes.
     *
     * Registered per-model by {@see ModelRegistrationHandler} because Psalm's
     * provider lookup requires exact class name matching.
     */
    public static function getModelMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if ($event->getMethodNameLowercase() !== 'all') {
            return null;
        }

        $calledClass = $event->getCalledFqClasslikeName() ?? $event->getFqClasslikeName();
        $codebase = $event->getSource()->getCodebase();

        /** @var class-string<Model> $calledClass */
        return self::buildCustomCollectionType($codebase, $calledClass);
    }

    /**
     * Build the custom collection type for a model, or null if no custom collection.
     *
     * @param class-string<Model> $modelClass
     * @return Union|null CustomCollection<int, TModel> or null to use default
     * @psalm-external-mutation-free
     */
    private static function buildCustomCollectionType(Codebase $codebase, string $modelClass): ?Union
    {
        $customCollection = self::resolveCustomCollection($codebase, $modelClass);
        if ($customCollection === null) {
            return null;
        }

        return new Union([
            new TGenericObject($customCollection, [
                Type::getInt(),
                new Union([new TNamedObject($modelClass)]),
            ]),
        ]);
    }

    /**
     * Resolve the custom collection class for a model, if any.
     *
     * Results are cached per model class for the duration of the analysis run.
     *
     * @param class-string<Model> $modelClass
     * @return class-string|null
     * @psalm-external-mutation-free
     */
    public static function resolveCustomCollection(Codebase $codebase, string $modelClass): ?string
    {
        if (\array_key_exists($modelClass, self::$cache)) {
            return self::$cache[$modelClass];
        }

        $result = self::resolveFromAttribute($codebase, $modelClass)
            ?? self::resolveFromNewCollectionReturnType($codebase, $modelClass);

        return self::$cache[$modelClass] = $result;
    }

    /**
     * Check for `#[CollectedBy(SomeCollection::class)]` attribute on the model class.
     *
     * Uses Psalm's ClassLikeStorage attributes (populated during scanning) rather than
     * runtime reflection — no class loading overhead.
     *
     * @return class-string|null
     * @psalm-mutation-free
     */
    private static function resolveFromAttribute(Codebase $codebase, string $modelClass): ?string
    {
        $storage = self::getClassStorage($codebase, $modelClass);
        if ($storage === null) {
            return null;
        }

        foreach ($storage->attributes as $attribute) {
            if ($attribute->fq_class_name !== CollectedBy::class) {
                continue;
            }

            // First argument is the collection class: #[CollectedBy(UserCollection::class)]
            $firstArg = $attribute->args[0] ?? null;
            if (!$firstArg instanceof AttributeArg) {
                return null;
            }

            if (!$firstArg->type instanceof Union) {
                return null;
            }

            foreach ($firstArg->type->getAtomicTypes() as $type) {
                if ($type instanceof TLiteralClassString) {
                    /** @var class-string */
                    return $type->value;
                }
            }

            return null;
        }

        return null;
    }

    /**
     * Check if the model overrides `newCollection()` with a narrowed return type.
     *
     * If the model (not a parent) declares newCollection() and its return type
     * is a concrete subclass of Eloquent\Collection, use that as the custom collection.
     *
     * @return class-string|null
     * @psalm-mutation-free
     */
    private static function resolveFromNewCollectionReturnType(Codebase $codebase, string $modelClass): ?string
    {
        $storage = self::getClassStorage($codebase, $modelClass);
        if ($storage === null) {
            return null;
        }

        // Only consider newCollection() if declared directly on this model (not inherited)
        $methodId = $storage->declaring_method_ids['newcollection'] ?? null;
        if ($methodId === null || \strtolower($methodId->fq_class_name) !== \strtolower($modelClass)) {
            return null;
        }

        try {
            $methodStorage = $codebase->methods->getStorage($methodId);
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            return null;
        }

        $returnType = $methodStorage->return_type;
        if (!$returnType instanceof Union) {
            return null;
        }

        foreach ($returnType->getAtomicTypes() as $type) {
            if (
                $type instanceof TNamedObject
                && $type->value !== EloquentCollection::class
                && \is_a($type->value, EloquentCollection::class, true)
            ) {
                return $type->value;
            }
        }

        return null;
    }

    /** @psalm-mutation-free */
    private static function getClassStorage(Codebase $codebase, string $modelClass): ?\Psalm\Storage\ClassLikeStorage
    {
        try {
            return $codebase->classlike_storage_provider->get($modelClass);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
