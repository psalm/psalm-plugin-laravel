<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\AbstractCursorPaginator;
use Illuminate\Pagination\AbstractPaginator;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\ModelPropertyResolver;
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
 * `$collectionClass` property, Builder methods like `get()`, `findMany()`,
 * Relation methods like `$user->roles()->get()`, and `Model::all()` should return
 * the custom collection type instead of `Illuminate\Database\Eloquent\Collection`.
 *
 * Detection is performed eagerly by {@see ModelRegistrationHandler} at codebase
 * population time, using runtime reflection (consistent with custom builder detection).
 * This handler only consumes the pre-registered mapping.
 *
 * @see https://laravel.com/docs/master/eloquent-collections#custom-collections
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/622
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/658
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

    /** @psalm-external-mutation-free */
    public static function reset(): void
    {
        self::$modelToCollectionMap = [];
    }

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
     * Relation subclasses whose collection-returning methods (get, findMany) should
     * be narrowed. Psalm's provider lookup requires exact class name matching, so
     * every concrete and abstract subclass must be listed.
     *
     * Matches the MethodForwardingHandler's source class list.
     *
     * @var list<class-string>
     */
    private const RELATION_CLASSES = [
        Relation::class,
        BelongsTo::class,
        BelongsToMany::class,
        HasMany::class,
        HasManyThrough::class,
        HasOne::class,
        HasOneOrMany::class,
        HasOneOrManyThrough::class,
        HasOneThrough::class,
        MorphMany::class,
        MorphOne::class,
        MorphOneOrMany::class,
        MorphTo::class,
        MorphToMany::class,
    ];

    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [
            Builder::class,
            ...self::RELATION_CLASSES,
            // Abstracts only: Psalm's provider lookup keys on the DECLARING class, and
            // getCollection() is declared here, not on the concrete LengthAwarePaginator /
            // Paginator / CursorPaginator subclasses.
            AbstractPaginator::class,
            AbstractCursorPaginator::class,
        ];
    }

    /** @psalm-external-mutation-free */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        // getCollection() is not in COLLECTION_METHODS: Builder/Relation have no such method,
        // so this early branch is unambiguous and cheaper than folding it into that list.
        if ($event->getMethodNameLowercase() === 'getcollection') {
            return self::paginatorCollectionType($event);
        }

        if (!\in_array($event->getMethodNameLowercase(), self::COLLECTION_METHODS, true)) {
            return null;
        }

        $source = $event->getSource();
        if (!$source instanceof StatementsAnalyzer) {
            return null;
        }

        $templateTypeParameters = $event->getTemplateTypeParameters();

        // Builder<TModel> and Relation<TRelatedModel, ...> both have the model at index 0.
        // Skip union types (e.g., MorphTo<Post|User>) — each model may use a different
        // custom collection, making the narrowing ambiguous.
        $templateUnion = $templateTypeParameters[0] ?? null;
        $modelClass = ModelPropertyResolver::extractModelFromUnion($templateUnion);
        if ($modelClass === null || self::hasMultipleModelTypes($templateUnion)) {
            return null;
        }

        $collectionClass = self::getCollectionClassForModel($modelClass);

        return $collectionClass !== null
            ? self::collectionType($collectionClass, $modelClass, $source->getCodebase())
            : null;
    }

    /**
     * Handle AbstractPaginator::getCollection() / AbstractCursorPaginator::getCollection().
     *
     * The stub's own conditional return already narrows to Eloquent\Collection when TValue
     * is a Model; this only further narrows that branch to a registered custom collection.
     * A userland paginator subclass that overrides getCollection() shifts the declaring
     * class off AbstractPaginator/AbstractCursorPaginator, so the provider correctly stops
     * firing for it — the override's own return type wins.
     *
     * @psalm-external-mutation-free
     */
    private static function paginatorCollectionType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $source = $event->getSource();
        if (!$source instanceof StatementsAnalyzer) {
            return null;
        }

        // AbstractPaginator<TKey, TValue> / AbstractCursorPaginator<TKey, TValue> — the
        // model lives at index 1 (TValue), unlike the Builder/Relation path above where
        // it's index 0.
        $templateTypeParameters = $event->getTemplateTypeParameters();
        $templateUnion = $templateTypeParameters[1] ?? null;
        $modelClass = ModelPropertyResolver::extractModelFromUnion($templateUnion);
        if ($modelClass === null || self::hasMultipleModelTypes($templateUnion)) {
            return null;
        }

        $collectionClass = self::getCollectionClassForModel($modelClass);

        // Unlike Builder<TModel> (no key template; TModel bounded `of Model`), the
        // receiver's own TKey/TValue are user-reachable here (setCollection(), through()),
        // so — unlike the Builder/Relation call site below — pass them through instead of
        // defaulting to `int`/a bare model type. Losing them (e.g. dropping a string key,
        // or `WorkOrder|null`) would make this an unsound rebuild, not a narrowing.
        return $collectionClass !== null
            ? self::collectionType(
                $collectionClass,
                $modelClass,
                $source->getCodebase(),
                $templateTypeParameters[0] ?? null,
                $templateUnion,
            )
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
     * Check if a Union contains more than one Model subclass type.
     *
     * Used to skip narrowing for union types like MorphTo<Post|User> where each
     * model may use a different custom collection.
     *
     * @psalm-mutation-free
     */
    private static function hasMultipleModelTypes(?Union $union): bool
    {
        if (!$union instanceof Union) {
            return false;
        }

        $count = 0;
        foreach ($union->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNamedObject && \is_a($atomic->value, Model::class, true)) {
                $count++;
                if ($count > 1) {
                    return true;
                }
            }
        }

        return false;
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
     * $keyUnion/$valueUnion let a caller whose receiver has a user-reachable TKey/TValue
     * (the paginator path) pass those through verbatim instead of the `int`/bare-model-type
     * defaults below, which are only sound for Builder<TModel>/Relation<TModel> (no key
     * template; TModel bounded `of Model`, so a bare TNamedObject can't lose anything).
     *
     * @psalm-mutation-free
     */
    private static function collectionType(
        string $collectionClass,
        string $modelClass,
        Codebase $codebase,
        ?Union $keyUnion = null,
        ?Union $valueUnion = null,
    ): Union {
        try {
            $storage = $codebase->classlike_storage_provider->get(\strtolower($collectionClass));
        } catch (\InvalidArgumentException) {
            return new Union([new TNamedObject($collectionClass)]);
        }

        if ($storage->template_types !== null && $storage->template_types !== []) {
            return new Union([
                new TGenericObject($collectionClass, [
                    $keyUnion ?? Type::getInt(),
                    $valueUnion ?? new Union([new TNamedObject($modelClass)]),
                ]),
            ]);
        }

        return new Union([new TNamedObject($collectionClass)]);
    }
}
