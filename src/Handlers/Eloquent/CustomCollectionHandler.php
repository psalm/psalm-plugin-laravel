<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Psalm\LaravelPlugin\Util\ModelPropertyResolver;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Narrows collection return types for models using custom Eloquent collections.
 *
 * When a model declares a custom collection via `#[CollectedBy(UserCollection::class)]`,
 * overriding `newCollection()` with a narrowed return type, or setting the
 * `$collectionClass` property, Builder methods like `get()`, `findMany()`, and
 * `Model::all()` should return the custom collection type instead of
 * `Illuminate\Database\Eloquent\Collection`.
 *
 * Detection is performed eagerly by {@see ModelRegistrationHandler} at codebase
 * population time, using runtime reflection (consistent with custom builder detection).
 * This handler only consumes the pre-registered mapping.
 *
 * @see https://laravel.com/docs/master/eloquent-collections#custom-collections
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/622
 * @internal
 */
final class CustomCollectionHandler implements MethodReturnTypeProviderInterface
{
    /**
     * Model FQCN → custom collection FQCN. Populated by {@see ModelRegistrationHandler}.
     *
     * @var array<string, string>
     */
    private static array $modelToCollectionMap = [];

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
     * Register a custom collection for a model. Called by {@see ModelRegistrationHandler}
     * during codebase population, after detecting #[CollectedBy] or newCollection() override.
     *
     * @param class-string<Model> $modelClass
     * @param class-string<EloquentCollection> $collectionClass
     * @psalm-external-mutation-free
     */
    public static function registerCustomCollection(string $modelClass, string $collectionClass): void
    {
        self::$modelToCollectionMap[$modelClass] = $collectionClass;
    }

    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [Builder::class];
    }

    /** @psalm-external-mutation-free */
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

        $collectionClass = self::getCollectionClassForModel($modelClass);

        return $collectionClass !== null
            ? self::collectionType($collectionClass, $modelClass)
            : null;
    }

    /**
     * Handle Model::all() for concrete model classes.
     *
     * Registered per-model by {@see ModelRegistrationHandler} because Psalm's
     * provider lookup requires exact class name matching.
     *
     * @psalm-external-mutation-free
     */
    public static function getModelMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if ($event->getMethodNameLowercase() !== 'all') {
            return null;
        }

        $calledClass = $event->getCalledFqClasslikeName() ?? $event->getFqClasslikeName();
        $collectionClass = self::getCollectionClassForModel($calledClass);

        /** @var class-string<Model> $calledClass */
        return $collectionClass !== null
            ? self::collectionType($collectionClass, $calledClass)
            : null;
    }

    /**
     * Look up the custom collection class for a model, or null if using default.
     *
     * @psalm-external-mutation-free
     */
    public static function getCollectionClassForModel(string $modelClass): ?string
    {
        return self::$modelToCollectionMap[$modelClass] ?? null;
    }

    /**
     * Build a generic type like `CustomCollection<int, TModel>`.
     *
     * @psalm-pure
     */
    private static function collectionType(string $collectionClass, string $modelClass): Union
    {
        return new Union([
            new TGenericObject($collectionClass, [
                Type::getInt(),
                new Union([new TNamedObject($modelClass)]),
            ]),
        ]);
    }
}
