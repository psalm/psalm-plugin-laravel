<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Metadata;

use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Guarded;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\Visible;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Psalm\Codebase;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\LaravelPlugin\Handlers\Eloquent\RelationMethodParser;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\CastResolver;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\CastsMethodParser;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\ColumnTypeMapper;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaStateProvider;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\EloquentModelMethods;
use Psalm\Progress\VoidProgress;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Storage\MethodStorage;
use Psalm\Type;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Builder / mutation surface for {@see ModelMetadataRegistry}.
 *
 * Kept separate so mutation does not appear on the registry's public API.
 * Called from:
 *   - {@see \Psalm\LaravelPlugin\Handlers\Eloquent\ModelRegistrationHandler}
 *     (production warm-up, during `AfterCodebasePopulated`).
 *   - `tests/Unit/` fixtures via {@see self::overrideForTesting()} / {@see self::reset()}.
 *
 * Computes schema + casts + traits + primary-key + cheap scalar fields, plus accessors,
 * mutators, relations, scopes, morph alias, and custom builder/collection detection.
 *
 * @psalm-api
 * @internal
 */
final class ModelMetadataRegistryBuilder
{
    /**
     * Pre-lowered trait FQCN keys. `ClassLikeStorage::$used_traits` is keyed by lowercased
     * FQCN; keeping the comparison keys pre-lowered keeps `computeTraitFlags` a straight
     * `isset()` per model without repeated `strtolower()` calls on the class constants.
     *
     * Sanctum / Passport traits may not be installed — we reference them as strings rather
     * than as `::class` so missing packages don't break plugin loading.
     */
    private const TRAIT_SOFT_DELETES_LC = 'illuminate\\database\\eloquent\\softdeletes';

    private const TRAIT_HAS_UUIDS_LC = 'illuminate\\database\\eloquent\\concerns\\hasuuids';

    private const TRAIT_HAS_ULIDS_LC = 'illuminate\\database\\eloquent\\concerns\\hasulids';

    private const TRAIT_HAS_FACTORY_LC = 'illuminate\\database\\eloquent\\factories\\hasfactory';

    private const TRAIT_NOTIFIABLE_LC = 'illuminate\\notifications\\notifiable';

    private const TRAIT_HAS_API_TOKENS_SANCTUM_LC = 'laravel\\sanctum\\hasapitokens';

    private const TRAIT_HAS_API_TOKENS_PASSPORT_LC = 'laravel\\passport\\hasapitokens';

    /**
     * Pre-compute metadata for a single model. Idempotent — re-computation
     * on a cached FQCN is a no-op.
     *
     * Never throws: on any failure the method logs a warning through the
     * codebase's progress handle and returns without storing an entry.
     *
     * Accepts any `class-string` (not narrowed to `class-string<Model>`): the
     * runtime guard in `compute()` early-returns for non-Model classes, and
     * unit-test fixtures pass non-Model FQCNs deliberately to exercise that path.
     *
     * @param class-string $modelFqcn
     */
    public static function warmUp(Codebase $codebase, string $modelFqcn): void
    {
        if (ModelMetadataRegistry::for($modelFqcn) instanceof ModelMetadata) {
            return;
        }

        try {
            $metadata = self::compute($codebase, $modelFqcn);
        } catch (\Throwable $throwable) {
            // Safety net: warm-up must never crash the plugin. Log and skip this model — but a
            // dropped model silently disables every registry-backed handler and rule for it
            // (BuilderScopeHandler, ModelPropertyAccessorHandler, UnknownModelAttribute, ...), so
            // the failure must reach the user. Progress::warning() writes to STDERR by default, but
            // VoidProgress (selected by --no-progress, a common quiet-CI flag) makes write() a total
            // no-op, silently swallowing the warning rather than merely hiding a progress bar. Write
            // directly to STDERR in that case so the failure is never fully invisible.
            $message = "Laravel plugin: ModelMetadataRegistry warm-up failed for '{$modelFqcn}': {$throwable->getMessage()} at {$throwable->getFile()}:{$throwable->getLine()}";

            $codebase->progress->warning($message);

            if ($codebase->progress instanceof VoidProgress) {
                \fwrite(\STDERR, 'Warning: ' . $message . \PHP_EOL);
            }

            return;
        }

        if (!$metadata instanceof ModelMetadata) {
            return;
        }

        // compute() returns non-null only after `is_a(..., Model::class, true)` succeeds,
        // so $modelFqcn is guaranteed to be a class-string<Model> at this point.
        /** @var class-string<Model> $modelFqcn */
        ModelMetadataRegistry::store($modelFqcn, $metadata);
    }

    /**
     * Seed a metadata entry directly, bypassing compute.
     *
     * @internal for tests under `tests/Unit/`
     * @param class-string<Model> $modelFqcn
     * @param ModelMetadata<Model> $metadata
     * @psalm-external-mutation-free
     */
    public static function overrideForTesting(string $modelFqcn, ModelMetadata $metadata): void
    {
        ModelMetadataRegistry::store($modelFqcn, $metadata);
    }

    /**
     * Clear all state derived from the current Laravel app and Psalm Codebase.
     * @psalm-external-mutation-free
     */
    public static function reset(): void
    {
        ModelMetadataRegistry::reset();
        self::$codebaseMethodsInitialized = null;
        self::$flippedMorphMap = null;
    }

    /**
     * @param class-string $modelFqcn
     * @return ModelMetadata<Model>|null
     */
    private static function compute(Codebase $codebase, string $modelFqcn): ?ModelMetadata
    {
        // §6.3 step 1: validate class
        if (!\is_a($modelFqcn, Model::class, true)) {
            return null;
        }

        // $modelFqcn is class-string<Model> here — narrowed by the is_a() guard above.
        $storageProvider = $codebase->classlike_storage_provider;
        if (!$storageProvider->has($modelFqcn)) {
            // Caller (ModelRegistrationHandler) iterates classlike_storage_provider::getAll(),
            // so missing storage here is unexpected — trace it so --debug runs reveal why a
            // model dropped out. Stays a null return (no user-visible failure).
            $codebase->progress->debug(
                "Laravel plugin: ModelMetadataRegistry skipped '{$modelFqcn}': storage provider has no entry\n",
            );

            return null;
        }

        $storage = $storageProvider->get($modelFqcn);

        try {
            $reflection = new \ReflectionClass($modelFqcn);
        } catch (\ReflectionException $reflectionException) {
            // The caller already verified is_a(..., Model::class) + storage presence,
            // so reflection failing here is unexpected — log at debug so --debug runs
            // surface what model lost its metadata and why.
            $codebase->progress->debug(
                "Laravel plugin: ModelMetadataRegistry could not reflect '{$modelFqcn}': {$reflectionException->getMessage()}\n",
            );

            return null;
        }

        // Custom builder / collection detection is reflection-based (abstract bases included). Warm-up
        // is the SINGLE detection pass: registerHandlersForModel() (run AFTER warmUp) reads these off
        // ModelMetadata and owns hook registration — no second reflection per model (Gotcha 8).
        $customBuilder = CustomTypeDetector::resolveCustomBuilderClass($codebase, $modelFqcn);
        $customCollection = CustomTypeDetector::resolveCustomCollectionClass($codebase, $modelFqcn);

        // §6.3 step 2: an abstract base cannot be instantiated (newInstanceWithoutConstructor()
        // throws \Error, not \ReflectionException). Its instance-derived fields (schema, casts,
        // table, connection) stay empty; the rest come from storage + declared property defaults.
        if ($reflection->isAbstract()) {
            return self::computeForAbstract(
                $codebase,
                $modelFqcn,
                $storage,
                $reflection,
                $customBuilder,
                $customCollection,
                $storageProvider,
            );
        }

        try {
            $instance = $reflection->newInstanceWithoutConstructor();
        } catch (\ReflectionException $reflectionException) {
            $codebase->progress->debug(
                "Laravel plugin: ModelMetadataRegistry could not instantiate '{$modelFqcn}': {$reflectionException->getMessage()}\n",
            );

            return null;
        }

        if (!$instance instanceof Model) {
            return null;
        }

        return self::computeForInstance(
            $codebase,
            $modelFqcn,
            $storage,
            $instance,
            $reflection,
            $customBuilder,
            $customCollection,
        );
    }

    /**
     * Concrete path: derive instance-backed fields via Laravel's public getters.
     *
     * @param class-string<Model>                   $modelFqcn
     * @param \ReflectionClass<Model>               $reflection
     * @param class-string<\Illuminate\Database\Eloquent\Builder>|null    $customBuilder
     * @param class-string<\Illuminate\Database\Eloquent\Collection>|null $customCollection
     * @return ModelMetadata<Model>
     */
    private static function computeForInstance(
        Codebase $codebase,
        string $modelFqcn,
        ClassLikeStorage $storage,
        Model $instance,
        \ReflectionClass $reflection,
        ?string $customBuilder,
        ?string $customCollection,
    ): ModelMetadata {
        $traits = self::computeTraitFlags($storage, $instance->usesTimestamps());

        // HasUuids / HasUlids override getKeyType(), getIncrementing(), and uniqueIds()
        // by reading `$this->usesUniqueIds`, which the trait initializer flips to true.
        // `newInstanceWithoutConstructor()` skips that initializer; flip the flag here
        // so every downstream Laravel getter (primary key, casts, etc.) sees the same
        // state the runtime would. See #591 review notes.
        if ($traits->hasUuids || $traits->hasUlids) {
            self::flipUsesUniqueIds($instance);
        }

        // Apply the class-level PHP-attribute config (#[Hidden]/#[Visible]/#[Appends]/#[Fillable]/
        // #[Guarded]/#[Connection]/#[Table]) that Model::__construct() would set via initializeTraits() /
        // initializeModelAttributes() — both skipped by newInstanceWithoutConstructor(). Mutating the
        // instance here (like flipUsesUniqueIds above) lets the getters below AND computeSchema()'s
        // getTable() see the runtime state.
        self::applyClassAttributeConfig($codebase, $reflection, $instance);

        $tableSchema = self::computeSchema($instance);

        // Method-derived metadata is STORAGE-based (no instance needed), so it is computed the same
        // way for concrete and abstract models — see computeForAbstract.
        [$accessors, $mutators, $scopes] = self::computeMethodMetadata($storage, $codebase->classlike_storage_provider);

        // Relations need the Codebase (the AST parser reads parsed file statements), unlike the
        // storage-only method metadata above — so they are computed separately, not in the walk.
        $relations = self::computeRelations($codebase, $modelFqcn, $storage);

        // Casts and appends are each needed twice — as their own field AND as a knownProperties()
        // source — so compute each once into a local rather than re-deriving for the second use.
        $casts = self::computeCasts($codebase, $modelFqcn, $instance, $traits, $tableSchema);
        $appends = self::filterStringList($instance->getAppends());

        return new ModelMetadata(
            fqcn: $modelFqcn,
            primaryKey: self::computePrimaryKey($instance, $traits),
            traits: $traits,
            // PHP-attribute config (#[Fillable]/#[Guarded]/#[Connection]/#[Table]/#[Appends]/#[Hidden]/
            // #[Visible]) was merged onto $instance by applyClassAttributeConfig() above, so these getters
            // now see it. Case is preserved — Laravel's isFillable / isGuarded / getHidden / getVisible do
            // exact-string comparisons, so lowercasing would diverge from runtime semantics.
            fillable: self::filterStringList($instance->getFillable()),
            // asArray() guards Laravel's `$guarded = false` ("guard nothing") idiom — getGuarded()
            // then returns a bool, not an array, and would TypeError filterStringList()'s array param,
            // crashing warm-up for the whole model (e.g. laravel/passport's models). #591.
            guarded: self::filterStringList(self::asArray($instance->getGuarded())),
            appends: $appends,
            with: self::readStringList($instance, 'with'),
            withCount: self::readStringList($instance, 'withCount'),
            hidden: self::filterStringList($instance->getHidden()),
            // getVisible() always returns an array (no `$visible = false` idiom, unlike $guarded), so
            // it needs no asArray() guard. Non-empty $visible is Eloquent's serialization allow-list.
            visible: self::filterStringList($instance->getVisible()),
            connection: $instance->getConnectionName(),
            morphAlias: self::computeMorphAlias($modelFqcn),
            customBuilder: $customBuilder,
            customCollection: $customCollection,
            schemaData: $tableSchema,
            castsData: $casts,
            accessorsData: $accessors,
            mutatorsData: $mutators,
            scopesData: $scopes,
            relationsData: $relations,
            knownPropertiesData: self::computeKnownProperties($tableSchema, $casts, $accessors, $mutators, $relations, $appends),
        );
    }

    /**
     * Abstract path: an abstract base has no instance, so the instance-derived fields are empty
     * and the storage/reflection-derived ones are read from declared property defaults
     * (initialized even without the constructor). Nothing consumes abstract metadata in Phase 1
     * (the property handlers register concrete-only); this exists so the registry never throws on
     * an abstract base and so future phases that resolve scopes/forwarded methods on abstract
     * receivers (issue #901) inherit a populated entry. Mirrors #1058's storage-vs-instance split.
     *
     * @param class-string<Model>                   $modelFqcn
     * @param \ReflectionClass<Model>               $reflection
     * @param class-string<\Illuminate\Database\Eloquent\Builder>|null    $customBuilder
     * @param class-string<\Illuminate\Database\Eloquent\Collection>|null $customCollection
     * @return ModelMetadata<Model>
     */
    private static function computeForAbstract(
        Codebase $codebase,
        string $modelFqcn,
        ClassLikeStorage $storage,
        \ReflectionClass $reflection,
        ?string $customBuilder,
        ?string $customCollection,
        ClassLikeStorageProvider $provider,
    ): ModelMetadata {
        /** @var array<string, mixed> $defaults */
        $defaults = $reflection->getDefaultProperties();

        // Method-derived metadata is storage-based, so an abstract base populates accessors/mutators/
        // scopes identically to a concrete model (no instantiation). This is what lets the migrated
        // handlers resolve an inherited accessor/scope on an abstract-typed receiver (#901).
        [$accessors, $mutators, $scopes] = self::computeMethodMetadata($storage, $provider);

        // Concrete relation methods declared in the abstract base's own body parse the same way as on
        // a concrete model (AST + storage, no instantiation).
        $relations = self::computeRelations($codebase, $modelFqcn, $storage);

        // Schema and casts are instance-derived — empty for an abstract base (no instance, no table).
        // Hoist the empty schema + the declared $appends so both the fields AND knownProperties()
        // read the same values (knownProperties is still meaningful here: accessors/relations/appends
        // declared on the abstract base populate it).
        $schema = new TableSchema([]);
        $appends = self::stringListDefault($defaults, 'appends');

        return new ModelMetadata(
            fqcn: $modelFqcn,
            primaryKey: self::computePrimaryKeyFromDefaults($defaults),
            traits: self::computeTraitFlags($storage, self::asBool($defaults['timestamps'] ?? null, true)),
            fillable: self::stringListDefault($defaults, 'fillable'),
            // Laravel's base Model defaults $guarded to ['*'] (guard-all); the default-property
            // read returns exactly that, so no special-casing is needed here.
            guarded: self::stringListDefault($defaults, 'guarded'),
            appends: $appends,
            with: self::stringListDefault($defaults, 'with'),
            withCount: self::stringListDefault($defaults, 'withCount'),
            hidden: self::stringListDefault($defaults, 'hidden'),
            visible: self::stringListDefault($defaults, 'visible'),
            // Instance-derived — empty for an abstract base (no instance, no table).
            connection: null,
            morphAlias: self::computeMorphAlias($modelFqcn),
            customBuilder: $customBuilder,
            customCollection: $customCollection,
            schemaData: $schema,
            castsData: [],
            accessorsData: $accessors,
            mutatorsData: $mutators,
            scopesData: $scopes,
            relationsData: $relations,
            knownPropertiesData: self::computeKnownProperties($schema, [], $accessors, $mutators, $relations, $appends),
        );
    }

    /**
     * Compute the full-callable accessor + mutator + scope maps for a model.
     *
     * "Full-callable" means self + traits + every method inherited from a USER ancestor — the same
     * set {@see \Psalm\LaravelPlugin\Handlers\Eloquent\ModelPropertyAccessorHandler} (accessors) and
     * {@see \Psalm\LaravelPlugin\Handlers\Eloquent\BuilderScopeHandler} (scopes) resolve through
     * `Codebase::methodExists()` (which is inheritance-aware). We reach it by replaying the ONE
     * canonical declaring-storage traversal, {@see EloquentModelMethods::appearingMethods()}, over the
     * class itself and each ancestor: `appearingMethods()` deliberately yields only methods *appearing
     * on* the iterated class (self-body + trait-hosted, never parent-inherited), so walking the chain
     * re-assembles the inherited set without a second, parallel method walk. The name-keyed maps
     * collapse the trait double-yields, and iterating self-before-ancestors makes the most-derived
     * declaration win.
     *
     * Accessors and scopes share this single pass — every classifier reads only the same yielded
     * MethodStorage, so adding scope classification costs no extra traversal.
     *
     * Storage-only (no instantiation, no `getMethodReturnType()`), so it runs identically for concrete
     * models and abstract bases (#901/#1058) and is safe at warm-up time.
     *
     * @return array{0: array<non-empty-lowercase-string, AccessorInfo>, 1: array<non-empty-lowercase-string, MutatorInfo>, 2: array<non-empty-lowercase-string, ScopeInfo>}
     */
    private static function computeMethodMetadata(
        ClassLikeStorage $storage,
        ClassLikeStorageProvider $provider,
    ): array {
        $accessors = [];
        $mutators = [];
        $scopes = [];

        foreach (self::callableMethodStorages($storage, $provider) as $methodStorage) {
            self::classifyAccessorMethod($methodStorage, $accessors, $mutators);
            self::classifyScopeMethod($methodStorage, $scopes);
        }

        return [$accessors, $mutators, $scopes];
    }

    /**
     * Yield the declaring MethodStorage of every method callable on $storage, walking the class and
     * its user ancestors via {@see EloquentModelMethods::appearingMethods()}. Framework ancestors are
     * skipped: a base like Model declares no user accessors, and its own `getAttribute()` etc. are
     * rejected by the classifier anyway, so skipping them merely bounds the walk. Mirrors the
     * `Illuminate\` skip in ModelRegistrationHandler's write-type pass.
     *
     * @return \Generator<lowercase-string, MethodStorage>
     */
    private static function callableMethodStorages(
        ClassLikeStorage $storage,
        ClassLikeStorageProvider $provider,
    ): \Generator {
        yield from EloquentModelMethods::appearingMethods($storage, $provider);

        // $storage->parent_classes is keyed by the lowercase FQCN with the original-case FQCN as the
        // value — so the framework skip must test the lowercase KEY (the value is e.g.
        // `Illuminate\Database\Eloquent\Model`). Skipping framework ancestors keeps the walk bounded to
        // user classes; their methods would be rejected by classifyAccessorMethod's defining_fqcln
        // guard anyway, so this is purely to avoid iterating Model's hundreds of methods per model.
        foreach ($storage->parent_classes as $parentNameLc => $parentName) {
            if (\str_starts_with($parentNameLc, 'illuminate\\')) {
                continue;
            }

            if (!$provider->has($parentName)) {
                continue;
            }

            yield from EloquentModelMethods::appearingMethods($provider->get($parentName), $provider);
        }
    }

    /**
     * Classify one method into the accessor and/or mutator maps, preserving the read handler's
     * resolution: attribute-style (`Attribute::make()`) beats legacy (`getXxxAttribute`) for the same
     * property, and the first declaration seen (most-derived, since the model is walked before its
     * ancestors) wins among same-kind methods.
     *
     * @param array<non-empty-lowercase-string, AccessorInfo> $accessors
     * @param array<non-empty-lowercase-string, MutatorInfo>  $mutators
     * @param-out array<non-empty-lowercase-string, AccessorInfo> $accessors
     * @param-out array<non-empty-lowercase-string, MutatorInfo>  $mutators
     */
    private static function classifyAccessorMethod(
        MethodStorage $methodStorage,
        array &$accessors,
        array &$mutators,
    ): void {
        $casedName = $methodStorage->cased_name;
        if ($casedName === null) {
            return;
        }

        // Framework methods are never user accessors; the `defining_fqcln` guard mirrors the
        // `Illuminate\` skip in ModelRegistrationHandler::registerWriteTypesForMethods().
        if ($methodStorage->defining_fqcln === null || \str_starts_with($methodStorage->defining_fqcln, 'Illuminate\\')) {
            return;
        }

        $lowercaseName = \strtolower($casedName);

        // Legacy accessor (getXxxAttribute) / mutator (setXxxAttribute), keyed by the SAME snake_case
        // normalizer the read handler looks up by. The kind detection and the bare
        // getAttribute()/setAttribute() exclusion live in EloquentModelMethods so they cannot drift
        // from the suppressor / visibility handler that share the convention.
        $legacyKind = EloquentModelMethods::legacyAccessorKind($lowercaseName);
        if ($legacyKind !== null) {
            $property = EloquentModelMethods::accessorPropertyKey(\substr($casedName, 3, -9));
            if ($property === null) {
                return;
            }

            if ($legacyKind === 'get') {
                $returnType = $methodStorage->return_type ?? $methodStorage->signature_return_type ?? Type::getMixed();
                self::insertAccessor($accessors, new LegacyAccessorInfo($property, $returnType, $methodStorage));
            } else {
                // setXxxAttribute may be write-only (no matching accessor).
                self::insertMutator($mutators, new LegacyMutatorInfo($property, $methodStorage));
            }

            return;
        }

        // Attribute-style: a method returning Illuminate\…\Casts\Attribute.
        $attribute = self::resolveAttributeReturn($methodStorage);
        if ($attribute === null) {
            return;
        }

        $property = EloquentModelMethods::accessorPropertyKey($casedName);
        if ($property === null) {
            return;
        }

        [$getType, $hasMutator, $setType] = $attribute;

        // Laravel treats a modern Attribute as a read accessor (getMutatedAttributes(), property read)
        // only when `get` is callable; a set-only `Attribute<never, TSet>` is a mutator alone. A `never`
        // TGet is that signal — skip the accessor insert so it can't override a serialized column/append
        // type or resolve a magic property read.
        if (!$getType->isNever()) {
            self::insertAccessor($accessors, new AttributeAccessorInfo($property, $getType, $methodStorage, $hasMutator));
        }

        if ($hasMutator) {
            self::insertMutator($mutators, new AttributeMutatorInfo($property, $methodStorage, $property, $setType));
        }
    }

    /**
     * Inspect a method's DECLARED return type for an `Attribute<TGet, TSet>` (or bare `Attribute`).
     * Returns `[TGet, hasMutator, TSet]`, or null when the method does not return an Attribute. Reads
     * the declared type only (no `getMethodReturnType()`): an attribute-style accessor must declare
     * `: Attribute` to work at runtime, so the signal is always on storage and the read is safe at
     * warm-up. `hasMutator` follows Laravel's write rule — a `never` TSet means read-only. TSet is the
     * setter type the write-path bakes into `pseudo_property_set_types` (mixed when TSet is absent or
     * the Attribute is bare).
     *
     * @return array{0: Union, 1: bool, 2: Union}|null
     * @psalm-mutation-free
     */
    private static function resolveAttributeReturn(MethodStorage $methodStorage): ?array
    {
        $returnType = $methodStorage->return_type ?? $methodStorage->signature_return_type;
        if (!$returnType instanceof Union) {
            return null;
        }

        foreach ($returnType->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject || !\is_a($atomic->value, Attribute::class, true)) {
                continue;
            }

            if (!$atomic instanceof TGenericObject) {
                // Bare `Attribute` (no generics): TGet is unknown (mixed) and the attribute is writable
                // with a mixed setter — mirrors the write-type pass treating a non-generic Attribute as
                // a mixed mutator.
                return [Type::getMixed(), true, Type::getMixed()];
            }

            $getType = $atomic->type_params[0] ?? Type::getMixed();
            // TSet absent (e.g. `Attribute<string>`) means a mixed setter, matching the write-path's
            // `$setType instanceof Union ? $setType : mixed` fallback.
            $setType = $atomic->type_params[1] ?? Type::getMixed();
            $hasMutator = !$setType->isNever();

            return [$getType, $hasMutator, $setType];
        }

        return null;
    }

    /**
     * Insert an accessor under its property key, applying the read handler's precedence:
     * attribute-style wins over legacy; otherwise the first (most-derived) entry stays.
     *
     * @param array<non-empty-lowercase-string, AccessorInfo> $accessors
     * @param-out array<non-empty-lowercase-string, AccessorInfo> $accessors
     */
    private static function insertAccessor(array &$accessors, AccessorInfo $info): void
    {
        $existing = $accessors[$info->propertyName] ?? null;
        if ($existing === null || ($existing instanceof LegacyAccessorInfo && $info instanceof AttributeAccessorInfo)) {
            $accessors[$info->propertyName] = $info;
        }
    }

    /**
     * Insert a mutator under its property key, mirroring {@see insertAccessor}'s precedence.
     *
     * @param array<non-empty-lowercase-string, MutatorInfo> $mutators
     * @param-out array<non-empty-lowercase-string, MutatorInfo> $mutators
     */
    private static function insertMutator(array &$mutators, MutatorInfo $info): void
    {
        $existing = $mutators[$info->propertyName] ?? null;
        if ($existing === null || ($existing instanceof LegacyMutatorInfo && $info instanceof AttributeMutatorInfo)) {
            $mutators[$info->propertyName] = $info;
        }
    }

    /**
     * Classify one method into the scope map. A method can produce BOTH a legacy and an attribute
     * entry — a `#[Scope]` on a `scopeXxx`-named method is callable both as `->scopeXxx()` (the
     * attribute key) and `->xxx()` (the legacy key), as the pre-registry
     * BuilderScopeHandler::resolveScopeMethodId resolved each form independently.
     *
     * One deliberate divergence from that call-driven predecessor: legacy detection goes through
     * {@see EloquentModelMethods::isLegacyScopeMethodName}, which requires the StudlyCase capital
     * after `scope` (so `scoped()`/`scopes()` are not mis-keyed as scopes `d`/`s`). An
     * all-lowercase `scopepublished()` is therefore NOT classified, whereas the old
     * `methodExists('scope'.ucfirst($name))` was case-insensitive and matched it. This is
     * vanishingly rare (it violates the universal `scopeStudly` convention) and aligns scope
     * detection with the emit-consumers (SuppressHandler / PublicScopeAccessorVisibilityHandler),
     * which already key off the same predicate. Verified zero-movement on the acceptance delta.
     *
     * Identity only: the {@see ScopeInfo} carries the declaring MethodStorage and the caller-facing
     * params (declared minus the leading `Builder $query`). `self`/`static` pinning is call-site
     * work the handler keeps (Correction 4 of the Phase-2 plan); the builder does NOT expand them.
     *
     * @param array<non-empty-lowercase-string, ScopeInfo> $scopes
     * @param-out array<non-empty-lowercase-string, ScopeInfo> $scopes
     */
    private static function classifyScopeMethod(MethodStorage $methodStorage, array &$scopes): void
    {
        $casedName = $methodStorage->cased_name;
        if ($casedName === null) {
            return;
        }

        // Framework methods are never user scopes; the `defining_fqcln` guard mirrors the
        // `Illuminate\` skip in classifyAccessorMethod() (and callableMethodStorages()).
        if ($methodStorage->defining_fqcln === null || \str_starts_with($methodStorage->defining_fqcln, 'Illuminate\\')) {
            return;
        }

        $lowercaseName = \strtolower($casedName);

        // Modern `#[Scope] public function published()` — keyed by the bare method name. The
        // visibility-gated attribute predicate lives in EloquentModelMethods so it cannot drift from
        // the SuppressHandler / visibility handler that share it (a private #[Scope] is rejected).
        if (EloquentModelMethods::hasScopeAttribute($methodStorage)) {
            $key = EloquentModelMethods::scopeKey($casedName);
            if ($key !== null) {
                self::insertScope($scopes, new AttributeScopeInfo($key, self::scopeCallerParams($methodStorage), $methodStorage));
            }
        }

        // Legacy `scopePublished()` — keyed by the name after the `scope` prefix is stripped.
        if (EloquentModelMethods::isLegacyScopeMethodName($lowercaseName, $casedName)) {
            $key = EloquentModelMethods::scopeKey(\substr($casedName, 5));
            if ($key !== null) {
                self::insertScope($scopes, new LegacyScopeInfo($key, self::scopeCallerParams($methodStorage), $methodStorage));
            }
        }
    }

    /**
     * Caller-facing scope params: the declared params minus the leading `Builder $query` that
     * Laravel injects via `Model::callNamedScope`. Mirrors the pre-registry
     * `array_slice($codebase->methods->getMethodParams($scopeMethodId), 1)` — equal for a scope,
     * whose declaring storage holds the same param list `getMethodParams` returns.
     *
     * @return list<FunctionLikeParameter>
     * @psalm-mutation-free
     */
    private static function scopeCallerParams(MethodStorage $methodStorage): array
    {
        return \array_slice($methodStorage->params, 1);
    }

    /**
     * Insert a scope under its normalized key, mirroring Laravel's `Model::callNamedScope`
     * precedence: an attribute-style scope wins over a legacy `scopeXxx` twin of the same name.
     * Among same-kind entries the first seen (most-derived, since the model is walked before its
     * ancestors) stays. Mirrors {@see insertAccessor}.
     *
     * @param array<non-empty-lowercase-string, ScopeInfo> $scopes
     * @param-out array<non-empty-lowercase-string, ScopeInfo> $scopes
     */
    private static function insertScope(array &$scopes, ScopeInfo $info): void
    {
        $existing = $scopes[$info->name] ?? null;
        if ($existing === null || ($existing instanceof LegacyScopeInfo && $info instanceof AttributeScopeInfo)) {
            $scopes[$info->name] = $info;
        }
    }

    /**
     * Compute the OWN-CLASS relation map: for each method declared in the model's own body, run the
     * AST relation parser and record the relation factory it returns.
     *
     * OWN-CLASS only because {@see RelationMethodParser::parse()} resolves a factory call only inside
     * a class literally named $modelFqcn (it searches the declaring file for that class name) — which
     * is exactly how the relation handlers call it, with the receiver FQCN. So `relations()[$name]`
     * equals `parse($receiver, $name)` for every name; inherited / trait-hosted relations are null in
     * both, and the handlers keep serving those through their `getMethodReturnType` tiers (this map
     * replaces only their AST-parse tier). NOT the full-callable ancestor walk used for
     * scopes/accessors — those are dispatched by name across the hierarchy, relations are body-parsed
     * per declaring class.
     *
     * Gated to relation CANDIDATES — own-body methods with no declared return type, or a
     * Relation-subclass return type. This reproduces the handlers' OWN gate exactly: both
     * {@see \Psalm\LaravelPlugin\Handlers\Eloquent\ModelRelationshipPropertyHandler::relationExists()}
     * and {@see \Psalm\LaravelPlugin\Handlers\Eloquent\ModelAggregatePropertyHandler}'s
     * `isRelationMethod()` reach their parse tier only for a method that is untyped or Relation-typed
     * (a declared non-Relation return type makes them classify by that type and decline before
     * parsing). So skipping non-Relation-typed methods here leaves the resolved set identical to the
     * pre-registry parse calls, while bounding the warm-up cost.
     *
     * parse() reads parsed file statements through the Codebase, so a unit-test Codebase built with
     * newInstanceWithoutConstructor() (no $methods, no file provider) yields an empty map — the same
     * guard {@see computeCasts} applies to its AST cast walk.
     *
     * @param class-string<Model> $modelFqcn
     * @return array<non-empty-lowercase-string, RelationInfo>
     */
    private static function computeRelations(Codebase $codebase, string $modelFqcn, ClassLikeStorage $storage): array
    {
        if (!self::codebaseMethodsInitialized($codebase)) {
            return [];
        }

        $relations = [];
        foreach ($storage->methods as $methodStorage) {
            $casedName = $methodStorage->cased_name;
            if ($casedName === null) {
                continue;
            }

            // A declared non-Relation return type means the handlers classify the method by that type
            // and never reach their parse tier — so skip it here too (keeps the set identical, and
            // avoids parsing every ordinary method body at warm-up).
            $returnType = $methodStorage->return_type ?? $methodStorage->signature_return_type;
            if ($returnType instanceof Union && !self::hasRelationAtomic($returnType)) {
                continue;
            }

            $parsed = RelationMethodParser::parse($codebase, $modelFqcn, $casedName);
            if ($parsed === null) {
                continue;
            }

            $key = \strtolower($casedName);
            if ($key === '') {
                continue;
            }

            // strtolower() yields a lowercase string at runtime; the non-empty guard above makes it
            // non-empty. Psalm 7 does not refine strtolower's output — assert what it cannot infer.
            /** @psalm-var non-empty-lowercase-string $key */
            $relations[$key] = new RelationInfo(
                name: $key,
                relationClass: $parsed['relationClass'],
                relatedModel: $parsed['relatedModel'],
                generics: [],
                intermediateModel: $parsed['intermediateModel'],
                pivotClass: $parsed['pivotModel'],
                pivotAccessor: $parsed['accessor'],
            );
        }

        return $relations;
    }

    /**
     * Whether any atomic in $type is a Relation subclass — the same signal the relation handlers use
     * to recognize a relation method by its declared return type.
     *
     * @psalm-mutation-free
     */
    private static function hasRelationAtomic(Union $type): bool
    {
        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNamedObject && \is_a($atomic->value, Relation::class, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the origin-tagged union of known property names. See {@see ModelMetadata::knownProperties()}
     * for the contract (key normalization, the source list, and the `$fillable`/`$guarded`/`@property`
     * exclusions). The schema, casts, accessors, mutators, and relations passed here are the very maps
     * stored on the same {@see ModelMetadata}, so this is a pure fold over already-derived data — no
     * reflection or Codebase access.
     *
     * @param array<non-empty-string, CastInfo>               $casts
     * @param array<non-empty-lowercase-string, AccessorInfo> $accessors
     * @param array<non-empty-lowercase-string, MutatorInfo>  $mutators
     * @param array<non-empty-lowercase-string, RelationInfo> $relations
     * @param list<non-empty-string>                          $appends
     * @return array<non-empty-lowercase-string, PropertyOrigins>
     */
    private static function computeKnownProperties(
        TableSchema $schema,
        array $casts,
        array $accessors,
        array $mutators,
        array $relations,
        array $appends,
    ): array {
        /** @var array<non-empty-lowercase-string, PropertyOrigins> $known */
        $known = [];

        foreach (\array_keys($schema->all()) as $column) {
            self::tagKnownProperty($known, $column, PropertyOrigin::SchemaColumn);
        }

        foreach (\array_keys($casts) as $column) {
            self::tagKnownProperty($known, $column, PropertyOrigin::Cast);
        }

        foreach (\array_keys($accessors) as $property) {
            self::tagKnownProperty($known, $property, PropertyOrigin::Accessor);
        }

        foreach (\array_keys($mutators) as $property) {
            self::tagKnownProperty($known, $property, PropertyOrigin::Mutator);
        }

        foreach (\array_keys($relations) as $relation) {
            self::tagKnownProperty($known, $relation, PropertyOrigin::Relation);
        }

        foreach ($appends as $appended) {
            self::tagKnownProperty($known, $appended, PropertyOrigin::Appended);
        }

        return $known;
    }

    /**
     * Merge one raw property name into the known-property accumulator under its normalized key,
     * adding $origin to the (possibly new) {@see PropertyOrigins} set. A name that normalizes to the
     * empty string is dropped. Mirrors {@see insertAccessor}'s by-ref accumulator convention.
     *
     * @param array<non-empty-lowercase-string, PropertyOrigins> $known
     * @param-out array<non-empty-lowercase-string, PropertyOrigins> $known
     */
    private static function tagKnownProperty(array &$known, string $rawName, PropertyOrigin $origin): void
    {
        $key = EloquentModelMethods::accessorPropertyKey($rawName);
        if ($key === null) {
            return;
        }

        $known[$key] = ($known[$key] ?? new PropertyOrigins([]))->with($origin);
    }

    /** @psalm-mutation-free */
    private static function computeTraitFlags(ClassLikeStorage $storage, bool $usesTimestamps): TraitFlags
    {
        $usedTraits = $storage->used_traits;

        return new TraitFlags(
            hasSoftDeletes: isset($usedTraits[self::TRAIT_SOFT_DELETES_LC]),
            hasUuids: isset($usedTraits[self::TRAIT_HAS_UUIDS_LC]),
            hasUlids: isset($usedTraits[self::TRAIT_HAS_ULIDS_LC]),
            hasFactory: isset($usedTraits[self::TRAIT_HAS_FACTORY_LC]),
            hasApiTokens: isset($usedTraits[self::TRAIT_HAS_API_TOKENS_SANCTUM_LC])
                || isset($usedTraits[self::TRAIT_HAS_API_TOKENS_PASSPORT_LC]),
            hasNotifications: isset($usedTraits[self::TRAIT_NOTIFIABLE_LC]),
            // Phase 3 concern — `Model::$globalScopes` is static app-boot state that can't be
            // read reliably here without running each trait's `addGlobalScope()` initializer.
            // See the Phase-3 ScopeVisibilityHandler (#695).
            hasGlobalScopes: false,
            usesTimestamps: $usesTimestamps,
        );
    }

    /**
     * Compute primary-key info from a model instance.
     *
     * HasUuids / HasUlids override `getKeyType()` / `getIncrementing()` / `uniqueIds()`
     * by reading `$this->usesUniqueIds`. The caller in `computeForInstance()` has already flipped
     * that flag for UUID/ULID models, so the instance getters return the runtime-correct
     * values here (including any user override of `uniqueIds()` returning multiple cols).
     */
    private static function computePrimaryKey(Model $instance, TraitFlags $traits): PrimaryKeyInfo
    {
        /** @var non-empty-string $keyName */
        $keyName = $instance->getKeyName();

        $keyType = $instance->getKeyType();
        $type = $keyType === 'string' ? PrimaryKeyType::String : PrimaryKeyType::Integer;

        $uuidColumns = [];
        if ($traits->hasUuids || $traits->hasUlids) {
            $uuidColumns = self::filterStringList($instance->uniqueIds());
        }

        return new PrimaryKeyInfo(
            name: $keyName,
            type: $type,
            incrementing: $instance->getIncrementing(),
            uuidColumns: $uuidColumns,
        );
    }

    /**
     * Compute primary-key info for an abstract base from its declared property defaults.
     *
     * No instance exists, so HasUuids/HasUlids key-type overrides (which run off the flipped
     * `$usesUniqueIds` instance flag) cannot apply — an abstract base carrying those traits would
     * report its raw `$keyType` default. This is acceptable: abstract bases declaring a unique-id
     * trait are pathological, and nothing reads abstract primary-key metadata in Phase 1.
     *
     * @param array<string, mixed> $defaults
     * @psalm-pure
     */
    private static function computePrimaryKeyFromDefaults(array $defaults): PrimaryKeyInfo
    {
        $keyName = self::asNonEmptyString($defaults['primaryKey'] ?? null) ?? 'id';
        $type = self::asNonEmptyString($defaults['keyType'] ?? null) === 'string'
            ? PrimaryKeyType::String
            : PrimaryKeyType::Integer;

        return new PrimaryKeyInfo(
            name: $keyName,
            type: $type,
            incrementing: self::asBool($defaults['incrementing'] ?? null, true),
            uuidColumns: [],
        );
    }

    /**
     * @param array<string, mixed> $defaults
     * @return list<non-empty-string>
     * @psalm-pure
     */
    private static function stringListDefault(array $defaults, string $key): array
    {
        return self::filterStringList(self::asArray($defaults[$key] ?? null));
    }

    /**
     * @return array<array-key, mixed>
     * @psalm-pure
     */
    private static function asArray(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }

    /** @psalm-pure */
    private static function asBool(mixed $value, bool $fallback): bool
    {
        return \is_bool($value) ? $value : $fallback;
    }

    /**
     * @return non-empty-string|null
     * @psalm-pure
     */
    private static function asNonEmptyString(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    private static function computeSchema(Model $instance): TableSchema
    {
        $schema = SchemaStateProvider::getSchema();
        if (!$schema instanceof \Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator) {
            return new TableSchema([]);
        }

        $tableName = $instance->getTable();
        if (!isset($schema->tables[$tableName])) {
            return new TableSchema([]);
        }

        $columns = [];
        foreach ($schema->tables[$tableName]->columns as $columnName => $column) {
            if ($columnName === '') {
                continue;
            }

            // Preserve original-case keys — Eloquent attribute access is case-sensitive.
            $columns[$columnName] = self::buildColumnInfo($column);
        }

        return new TableSchema($columns);
    }

    /** @psalm-mutation-free */
    private static function buildColumnInfo(SchemaColumn $column): ColumnInfo
    {
        /** @var non-empty-string $name */
        $name = $column->name;
        /** @var non-empty-string $sqlType */
        $sqlType = $column->type;

        return new ColumnInfo(
            name: $name,
            sqlType: $sqlType,
            nullable: $column->nullable,
            hasDefault: $column->default instanceof \Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumnDefault,
            unsigned: $column->unsigned,
            options: \array_values($column->options),
        );
    }

    /**
     * @param class-string<Model> $modelFqcn
     * @return array<non-empty-string, CastInfo>
     */
    private static function computeCasts(
        Codebase $codebase,
        string $modelFqcn,
        Model $instance,
        TraitFlags $traits,
        TableSchema $schema,
    ): array {
        $merged = [];

        // 1. SoftDeletes adds its deleted-at column as a `datetime` cast via the trait
        //    initializer, which `newInstanceWithoutConstructor()` skips. Mirror that
        //    manually (lowest priority). Honor the `DELETED_AT` class-constant override
        //    (`const DELETED_AT = 'archived_at';` is the Laravel-documented pattern).
        if ($traits->hasSoftDeletes) {
            $merged[self::resolveDeletedAtColumn($instance)] = 'datetime';
        }

        // 2. $instance->getCasts() walks inheritance + merges $this->casts and static::casts().
        //    The caller already flipped $usesUniqueIds on HasUuids/HasUlids models, so
        //    getIncrementing() / getKeyType() return the correct UUID/ULID values here
        //    and getCasts() no longer injects a bogus [keyName => 'int'] entry.
        /** @var array<string, string> $instanceCasts */
        $instanceCasts = $instance->getCasts();
        $merged = \array_merge($merged, $instanceCasts);

        // 3. casts() method (AST-parsed) overrides #2 when both declare the same key.
        //    CastsMethodParser calls Codebase::methodExists(), which dereferences the
        //    non-nullable Codebase::$methods. A Codebase constructed via
        //    newInstanceWithoutConstructor() (unit-test fixtures) leaves it
        //    uninitialized; skip the AST walk there. Production Codebases always
        //    have $methods wired, so the check result is stable per process and cached.
        if (self::codebaseMethodsInitialized($codebase)) {
            $merged = \array_merge($merged, CastsMethodParser::parse($codebase, $modelFqcn));
        }

        $result = [];
        foreach ($merged as $columnName => $castString) {
            if ($columnName === '' || $castString === '') {
                continue;
            }

            // Bake column nullability into CastInfo::$psalmType at build time so consumers
            // can read it directly without re-running CastResolver (see design §5.4).
            $column = $schema->column($columnName);
            $nullable = $column instanceof ColumnInfo && $column->nullable;
            // CastsInboundAttributes casts read back as a passthrough of the column's intrinsic
            // type, so CastResolver needs that base type as `$originalType`. The mapping lives on
            // ColumnTypeMapper (Schema namespace) so the builder reads it directly instead of
            // reaching back into the property handler; null when no migration column backs the cast.
            $originalType = $column instanceof ColumnInfo
                ? ColumnTypeMapper::mapBaseType($column)
                : null;
            // Preserve original-case column keys to match Eloquent's case-sensitive
            // attribute semantics (callers pass the property name as written).
            $result[$columnName] = self::buildCastInfo($codebase, $columnName, $castString, $nullable, $originalType);
        }

        return $result;
    }

    /**
     * Build a {@see CastInfo} from a raw cast string (e.g. `'datetime'`, `'App\\Enums\\Status'`,
     * `'encrypted:array'`, `'App\\Casts\\Money:usd'`).
     *
     * `$nullable` controls only {@see CastInfo::$psalmType} — the discriminator shape is
     * nullability-independent. `$originalType` is the column's intrinsic (non-nullable) mapped
     * type, forwarded to {@see CastResolver::resolve} for the CastsInboundAttributes passthrough.
     *
     * @param non-empty-string $columnName
     * @param non-empty-string $castString
     */
    private static function buildCastInfo(
        Codebase $codebase,
        string $columnName,
        string $castString,
        bool $nullable,
        ?Union $originalType,
    ): CastInfo {
        [$shape, $targetClass, $parameter] = self::classifyCast($castString);

        return new CastInfo(
            column: $columnName,
            shape: $shape,
            targetClass: $targetClass,
            psalmType: CastResolver::resolve($codebase, $castString, $nullable, $originalType),
            parameter: $parameter,
        );
    }

    /**
     * Classify a cast string into its {@see CastShape} + optional target class FQCN + parameter.
     *
     * Best-effort for Phase 1 — Phase 2/3 consumers that need more precise shape information
     * may extend the classifier. `psalmType` is the authoritative resolved type.
     *
     * @return array{0: CastShape, 1: class-string|null, 2: string|null}
     */
    private static function classifyCast(string $castString): array
    {
        // `encrypted:X` wraps another cast — recurse after stripping the prefix.
        if (\str_starts_with(\strtolower($castString), 'encrypted:')) {
            [$innerShape, $innerTarget, $innerParam] = self::classifyCast(\substr($castString, 10));
            // Preserve the inner shape's precision; the outer wrapper just marks Primitive as encrypted.
            $shape = $innerShape === CastShape::Primitive ? CastShape::AsEncrypted : $innerShape;

            return [$shape, $innerTarget, $innerParam];
        }

        $colonPos = \strpos($castString, ':');
        if ($colonPos !== false) {
            $base = \substr($castString, 0, $colonPos);
            $parameter = \substr($castString, $colonPos + 1);
        } else {
            $base = $castString;
            $parameter = null;
        }

        $baseLower = \strtolower($base);

        $shape = CastShape::Primitive;
        /** @var class-string|null $targetClass */
        $targetClass = null;

        if (
            \in_array($baseLower, ['date', 'datetime', 'custom_datetime', 'immutable_date', 'immutable_datetime', 'immutable_custom_datetime'], true)
        ) {
            $shape = CastShape::DateTime;
        } elseif (self::looksLikeClassName($base) && self::isEnumClass($base)) {
            /** @var class-string $base */
            $shape = CastShape::BackedEnum;
            $targetClass = $base;
        } elseif (
            self::looksLikeClassName($base)
            // autoload: false — classifyCast is best-effort shape metadata. Skipping
            // autoload here avoids eager file includes during warm-up for casts whose
            // target class isn't loaded yet. CastResolver::resolve (separate call) is
            // the authoritative path for $psalmType and keeps its existing autoload
            // behavior for backwards compatibility with pre-registry resolution.
            && \class_exists($base, false)
            // Castable (AsCollection, AsArrayObject, AsStringable, AsEnumCollection, ...) alongside
            // CastsAttributes: both are class-castable per Model::isClassCastable(), but a Castable
            // itself implements neither CastsAttributes nor CastsInboundAttributes directly — only the
            // instance its castUsing() returns does — so checking CastsAttributes alone missed every
            // framework Castable wrapper (and any user Castable-only class), wrongly classifying them
            // Primitive and letting an accessor on the same column win over the class cast.
            && (
                \is_a($base, \Illuminate\Contracts\Database\Eloquent\CastsAttributes::class, true)
                || \is_a($base, \Illuminate\Contracts\Database\Eloquent\Castable::class, true)
            )
        ) {
            /** @var class-string $base */
            $shape = CastShape::CustomCastsAttributes;
            $targetClass = $base;
        }

        return [$shape, $targetClass, $parameter];
    }

    /**
     * Checks for a class-like identifier (avoids triggering autoload on every cast key).
     *
     * @psalm-pure
     */
    private static function looksLikeClassName(string $value): bool
    {
        return \str_contains($value, '\\') || \preg_match('/^[A-Z]/', $value) === 1;
    }

    private static function isEnumClass(string $class): bool
    {
        // autoload: false — best-effort shape detection only. If the enum hasn't
        // already been loaded by the time we warm up, classifyCast falls back to
        // Primitive, and `CastResolver::resolve` (called separately by buildCastInfo)
        // still produces the authoritative `$psalmType`.
        return \enum_exists($class, false);
    }

    /**
     * Morph map is a process-wide registry that doesn't change between warm-ups, so
     * we flip it once and do O(1) isset lookups per model instead of O(n) array_search.
     *
     * @var array<class-string, string>|null
     */
    private static ?array $flippedMorphMap = null;

    /**
     * @param class-string<Model> $modelFqcn
     */
    private static function computeMorphAlias(string $modelFqcn): ?string
    {
        if (self::$flippedMorphMap === null) {
            $morphMap = Relation::morphMap();
            /** @var array<class-string, string> $flipped */
            $flipped = \array_flip($morphMap);
            self::$flippedMorphMap = $flipped;
        }

        return self::$flippedMorphMap[$modelFqcn] ?? null;
    }

    /**
     * Read a protected array-of-string property from the model instance via reflection.
     *
     * Used for `$with` / `$withCount` — these have no public getters, but class-declared
     * default values are initialized even by `newInstanceWithoutConstructor()`.
     *
     * Only catches ReflectionException (property genuinely missing on a subclass that
     * shadowed it). Any other Error surfaces via warmUp()'s outer catch as a warning,
     * which is the right behavior when something unexpected breaks.
     *
     * @return list<string>
     */
    private static function readStringList(Model $instance, string $propertyName): array
    {
        try {
            $property = new \ReflectionProperty($instance, $propertyName);
            $value = $property->getValue($instance);
        } catch (\ReflectionException) {
            return [];
        }

        if (!\is_array($value)) {
            return [];
        }

        return self::filterStringList($value);
    }

    /**
     * Resolve SoftDeletes' deleted-at column. Laravel reads `static::DELETED_AT` when
     * defined (see `SoftDeletes::getDeletedAtColumn()`), otherwise defaults to
     * `'deleted_at'`. We replicate that without invoking the trait method, since calling
     * a trait method through a `Model` variable fails Psalm's type check.
     *
     * @psalm-pure
     */
    private static function resolveDeletedAtColumn(Model $instance): string
    {
        $constantName = $instance::class . '::DELETED_AT';
        if (\defined($constantName)) {
            // Inline \constant() into the mixed-param helper so the mixed value never binds to a
            // local — keeps the file at 100% type coverage (a `@psalm-var mixed` local would
            // still count against the mixed-expression tally).
            return self::asNonEmptyString(\constant($constantName)) ?? 'deleted_at';
        }

        return 'deleted_at';
    }

    /**
     * Flip `$usesUniqueIds = true` on a HasUuids/HasUlids instance so `getKeyType()` and
     * `getIncrementing()` return the string/non-incrementing values Laravel would return
     * at runtime — work that the trait initializer normally handles.
     */
    private static function flipUsesUniqueIds(Model $instance): void
    {
        try {
            $property = new \ReflectionProperty($instance, 'usesUniqueIds');
        } catch (\ReflectionException) {
            return;
        }

        $property->setValue($instance, true);
    }

    /**
     * Apply the class-level PHP-attribute config that {@see Model}::__construct() sets via
     * `initializeTraits()` / `initializeModelAttributes()` — both skipped by
     * `newInstanceWithoutConstructor()`. Mutates `$instance` (like {@see flipUsesUniqueIds()}) so every
     * downstream getter, and {@see computeSchema()}'s `getTable()`, sees the runtime state.
     *
     * Per-attribute semantics mirror Laravel exactly: `#[Hidden]`/`#[Visible]`/`#[Appends]`/`#[Fillable]`
     * union-merge into the property list; `#[Guarded]`/`#[Unguarded]` only replace the default `['*']`;
     * `#[Connection]`/`#[Table]` fill a null.
     *
     * Known gap (NOT applied): `#[Table]`'s `key` / `keyType` / `incrementing` sub-overrides and
     * `#[WithoutIncrementing]`. Laravel's `initializeModelAttributes()` feeds these into the primary key
     * (`primaryKey ← $table->key` when still `'id'`, etc.), but {@see computePrimaryKey()} reads only the
     * raw `getKeyName()`/`getKeyType()`/`getIncrementing()` defaults and never consults `#[Table]`, so an
     * attribute-declared PK is not picked up. Deferred as a separate PK-path change; the table NAME (the
     * serialization-relevant part) IS applied. (Timestamps are moot — the registry stores no such field.)
     *
     * The attribute classes exist from Laravel 13.0; on older lines `getAttributes()` matches nothing and
     * every branch no-ops (so the plugin stays correct across the 12.4+ support range).
     *
     * @param \ReflectionClass<Model> $reflection
     */
    private static function applyClassAttributeConfig(Codebase $codebase, \ReflectionClass $reflection, Model $instance): void
    {
        // Union-merge, mirroring mergeHidden() / mergeVisible() / mergeAppends() / mergeFillable().
        // These four configuration attributes only exist from Laravel 13. On Laravel 12.14–12.24,
        // mergeHidden() / mergeVisible() / mergeAppends() are unavailable (mergeFillable() exists), so
        // calling the absent helpers with empty fallbacks crashes warm-up. An absent attribute has nothing to replay.
        $hidden = self::classAttribute($codebase, $reflection, Hidden::class);
        if ($hidden !== null) {
            $instance->mergeHidden($hidden->columns);
        }

        $visible = self::classAttribute($codebase, $reflection, Visible::class);
        if ($visible !== null) {
            $instance->mergeVisible($visible->columns);
        }

        $appends = self::classAttribute($codebase, $reflection, Appends::class);
        if ($appends !== null) {
            $instance->mergeAppends($appends->columns);
        }

        $fillable = self::classAttribute($codebase, $reflection, Fillable::class);
        if ($fillable !== null) {
            $instance->mergeFillable($fillable->columns);
        }

        self::applyGuardedAttribute($codebase, $reflection, $instance);
        self::applyConnectionAttribute($codebase, $reflection, $instance);
        self::applyTableAttribute($codebase, $reflection, $instance);
    }

    /**
     * `#[Guarded]` / `#[Unguarded]` only replace the default `['*']` denylist, mirroring
     * {@see \Illuminate\Database\Eloquent\Concerns\GuardsAttributes::initializeGuardsAttributes()}:
     * `#[Unguarded]` guards nothing; else the `#[Guarded]` columns (`columns ?? ['*']`, so a present-but-
     * empty `#[Guarded]` also guards nothing); absent → keep `['*']`.
     *
     * @param \ReflectionClass<Model> $reflection
     */
    private static function applyGuardedAttribute(Codebase $codebase, \ReflectionClass $reflection, Model $instance): void
    {
        if ($instance->getGuarded() !== ['*']) {
            return;
        }

        if (self::classAttribute($codebase, $reflection, Unguarded::class) !== null) {
            $instance->guard([]);

            return;
        }

        // Presence (not non-empty) gates the replace: a present `#[Guarded()]` with no columns sets [],
        // matching Laravel's `columns ?? ['*']` where an empty array is non-null. Absent → keep `['*']`.
        $guarded = self::classAttribute($codebase, $reflection, Guarded::class);
        if ($guarded !== null) {
            $instance->guard($guarded->columns);
        }
    }

    /**
     * `#[Connection(name:)]` fills a null `$connection`, mirroring `initializeModelAttributes()`'s `??=`.
     * The name is normalized to a string before storing, matching `Model::getConnectionName()`'s
     * `enum_value()` (BackedEnum → backing value, UnitEnum → case name). This is deliberately stronger
     * than storing the raw enum: the registry's `connection` field is `?string`, and an int-backed enum
     * would make `getConnectionName()` return an `int` that TypeErrors the `?string` parameter under
     * `strict_types`, dropping the whole model via warmUp()'s catch.
     *
     * @param \ReflectionClass<Model> $reflection
     */
    private static function applyConnectionAttribute(Codebase $codebase, \ReflectionClass $reflection, Model $instance): void
    {
        if ($instance->getConnectionName() !== null) {
            return;
        }

        $name = self::classAttribute($codebase, $reflection, Connection::class)?->name;
        if ($name === null) {
            return;
        }

        $instance->setConnection(match (true) {
            \is_string($name) => $name,
            $name instanceof \BackedEnum => (string) $name->value,
            default => $name->name,
        });
    }

    /**
     * `#[Table(name:)]` sets the table, mirroring the `$declaresTable` branch of
     * `initializeModelAttributes()`: a `#[Table]` declared DIRECTLY on the concrete class (Laravel's
     * non-recursive `getAttributes()` check) AND no own `$table` property overwrites the table; otherwise
     * the resolved (ancestor-walked) name only fills a still-null table (`??=`). Feeds
     * {@see computeSchema()}'s migration lookup.
     *
     * Known-gap: a `key`/`keyType`-only `#[Table]` (null name) does NOT clear an inherited `$table`
     * default the way Laravel's force-branch does (`$this->table = $table->name ?? null`). That sits
     * inside the same exotic, deferred scenario as the `key`/`keyType`/`incrementing` PK sub-overrides
     * (see {@see applyClassAttributeConfig()}), so it is left untouched rather than half-applied.
     *
     * @param \ReflectionClass<Model> $reflection
     */
    private static function applyTableAttribute(Codebase $codebase, \ReflectionClass $reflection, Model $instance): void
    {
        $name = self::classAttribute($codebase, $reflection, Table::class)?->name;
        if ($name === null) {
            return;
        }

        // Force-set only on a DIRECT concrete-class attribute (non-recursive, no own $table property);
        // otherwise `??=` fills a still-null table with the resolved name.
        $forceSet = !self::declaresOwnProperty($reflection, 'table') && $reflection->getAttributes(Table::class) !== [];
        if ($forceSet || self::rawTableIsNull($instance)) {
            $instance->setTable($name);
        }
    }

    /**
     * True when the concrete class itself declares `$name` (not inherited) — mirrors the `$declaresTable`
     * check in `initializeModelAttributes()`.
     *
     * @param \ReflectionClass<Model> $reflection
     * @psalm-pure
     */
    private static function declaresOwnProperty(\ReflectionClass $reflection, string $name): bool
    {
        if (!$reflection->hasProperty($name)) {
            return false;
        }

        return $reflection->getProperty($name)->getDeclaringClass()->getName() === $reflection->getName();
    }

    /**
     * Whether the raw `$table` property is still null (so a `#[Table]` may fill it). Reads the property,
     * not `getTable()`, which always derives a non-null name from the class.
     */
    private static function rawTableIsNull(Model $instance): bool
    {
        try {
            $property = new \ReflectionProperty($instance, 'table');
        } catch (\ReflectionException) {
            return true;
        }

        // A typed-uninitialized `$table` (non-idiomatic) has no value to read; treat it as null so a
        // `#[Table]` may fill it, and avoid the \Error that getValue() throws on an uninitialized typed prop.
        if (!$property->isInitialized($instance)) {
            return true;
        }

        // Consume the mixed value inline (=== null) so it never binds to a local — keeps coverage at 100%.
        return $property->getValue($instance) === null;
    }

    /**
     * Resolve a class-level attribute the way {@see Model}::resolveClassAttribute() does: walk the class
     * up its parents, return the first ancestor's first instance of `$attributeClass` (no cross-ancestor
     * merge), or null. A throwing `newInstance()` (malformed attribute args) is swallowed to a null so a
     * single bad attribute degrades that one field rather than aborting the whole model's warm-up; this
     * is deliberately broader than Laravel's own `catch (Exception)`, since a static-analysis pass must
     * never crash where the runtime constructor would. The swallow leaves a debug breadcrumb, like the
     * file's other reflection catches ({@see compute()}, {@see warmUp()}).
     *
     * @template T of object
     * @param \ReflectionClass<object> $reflection
     * @param class-string<T>          $attributeClass
     * @return T|null
     */
    private static function classAttribute(Codebase $codebase, \ReflectionClass $reflection, string $attributeClass): ?object
    {
        for ($current = $reflection; $current !== false; $current = $current->getParentClass()) {
            $attributes = $current->getAttributes($attributeClass);
            if ($attributes === []) {
                continue;
            }

            try {
                return $attributes[0]->newInstance();
            } catch (\Throwable $throwable) {
                $codebase->progress->debug(
                    "Laravel plugin: ModelMetadataRegistry could not instantiate {$attributeClass} on '{$current->getName()}': {$throwable->getMessage()}\n",
                );

                return null;
            }
        }

        return null;
    }

    /**
     * Cache whether Psalm's Codebase::$methods is initialized in the running process.
     *
     * Production builds wire this dependency during Psalm's own bootstrap, so the answer
     * is fixed for the lifetime of the process. Unit-test fixtures that construct
     * Codebase via newInstanceWithoutConstructor() leave it uninitialized and stay in the
     * "false" branch for the whole test run. A single ReflectionProperty allocation
     * per process beats one per model warm-up.
     */
    private static ?bool $codebaseMethodsInitialized = null;

    private static function codebaseMethodsInitialized(Codebase $codebase): bool
    {
        if (self::$codebaseMethodsInitialized !== null) {
            return self::$codebaseMethodsInitialized;
        }

        try {
            $property = new \ReflectionProperty($codebase, 'methods');
        } catch (\ReflectionException) {
            // Codebase::$methods absent altogether — treat as uninitialized.
            return self::$codebaseMethodsInitialized = false;
        }

        return self::$codebaseMethodsInitialized = $property->isInitialized($codebase);
    }

    /**
     * @param array<array-key, mixed> $values
     * @return list<non-empty-string>
     * @psalm-pure
     */
    private static function filterStringList(array $values): array
    {
        /** @var list<non-empty-string> */
        return \array_values(\array_filter(
            $values,
            static fn(mixed $entry): bool => \is_string($entry) && $entry !== '',
        ));
    }
}
