<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
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

        $source = $event->getSource();
        if (!$source instanceof StatementsAnalyzer) {
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
            ? self::collectionType($collectionClass, $modelClass, $source->getCodebase())
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

        $source = $event->getSource();
        if (!$source instanceof StatementsAnalyzer) {
            return null;
        }

        $calledClass = $event->getCalledFqClasslikeName() ?? $event->getFqClasslikeName();
        $collectionClass = self::getCollectionClassForModel($calledClass);

        /** @var class-string<Model> $calledClass */
        return $collectionClass !== null
            ? self::collectionType($collectionClass, $calledClass, $source->getCodebase())
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
     * Build a type for the custom collection.
     *
     * If the collection class declares template parameters, returns a generic type like
     * `CustomCollection<int, TModel>`. If it has no template params (e.g., a collection
     * that extends `EloquentCollection<int, ConcreteModel>` without its own @template),
     * returns a plain `TNamedObject` to avoid TooManyTemplateParams.
     *
     * Mirrors the template-param check in {@see ModelMethodHandler::builderType()}.
     *
     * Assumes custom collections always have exactly 2 class-level template parameters
     * (TKey, TModel) inherited from EloquentCollection. This holds for all practical
     * custom collection patterns in the Laravel ecosystem.
     *
     * @psalm-mutation-free
     */
    private static function collectionType(string $collectionClass, string $modelClass, Codebase $codebase): Union
    {
        try {
            $storage = $codebase->classlike_storage_provider->get(\strtolower($collectionClass));
        } catch (\InvalidArgumentException) {
            return new Union([new TNamedObject($collectionClass)]);
        }

        if ($storage->template_types !== null && $storage->template_types !== []) {
            return new Union([
                new TGenericObject($collectionClass, [
                    Type::getInt(),
                    new Union([new TNamedObject($modelClass)]),
                ]),
            ]);
        }

        return new Union([new TNamedObject($collectionClass)]);
    }
}
