<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers\ModelMetadata;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\CastResolver;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\CastsMethodParser;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaColumn;
use Psalm\LaravelPlugin\Providers\ModelMetadataRegistry;
use Psalm\LaravelPlugin\Providers\SchemaStateProvider;
use Psalm\Storage\ClassLikeStorage;

/**
 * Builder / mutation surface for {@see ModelMetadataRegistry}.
 *
 * Kept separate so mutation does not appear on the registry's public API.
 * Called from:
 *   - {@see \Psalm\LaravelPlugin\Handlers\Eloquent\ModelRegistrationHandler}
 *     (production warm-up, during `AfterCodebasePopulated`).
 *   - `tests/Unit/` fixtures via {@see self::overrideForTesting()} / {@see self::reset()}.
 *
 * Phase 1 scope: computes schema + casts + traits + primary-key + cheap scalar fields.
 * Accessors / mutators / relations / scopes / morph alias / custom builder / custom
 * collection are left for Phase 2 PRs per the design doc §7.
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
        if (ModelMetadataRegistry::for($modelFqcn) instanceof \Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata) {
            return;
        }

        try {
            $metadata = self::compute($codebase, $modelFqcn);
        } catch (\Throwable $throwable) {
            // Safety net: warm-up must never crash the plugin. Log and skip this model.
            $codebase->progress->warning(
                "Laravel plugin: ModelMetadataRegistry warm-up failed for '{$modelFqcn}': {$throwable->getMessage()} at {$throwable->getFile()}:{$throwable->getLine()}",
            );

            return;
        }

        if (!$metadata instanceof \Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata) {
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
     * Clear all cached metadata and the captured Progress handle.
     *
     * @internal for tests under `tests/Unit/`
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

        // §6.3 step 2: instantiate without constructor — matches ModelPropertyHandler
        $instance = self::instantiate($modelFqcn, $codebase);
        if (!$instance instanceof \Illuminate\Database\Eloquent\Model) {
            return null;
        }

        // §6.3 step 3: derive via Laravel's public methods
        $traits = self::computeTraitFlags($storage, $instance);

        // HasUuids / HasUlids override getKeyType(), getIncrementing(), and uniqueIds()
        // by reading `$this->usesUniqueIds`, which the trait initializer flips to true.
        // `newInstanceWithoutConstructor()` skips that initializer; flip the flag here
        // so every downstream Laravel getter (primary key, casts, etc.) sees the same
        // state the runtime would. See #591 review notes.
        if ($traits->hasUuids || $traits->hasUlids) {
            self::flipUsesUniqueIds($instance);
        }

        $primaryKey = self::computePrimaryKey($instance, $traits);
        $tableSchema = self::computeSchema($instance);
        $casts = self::computeCasts($codebase, $modelFqcn, $instance, $traits, $tableSchema);

        // Preserve case — Laravel's isFillable / isGuarded / getHidden do exact-string
        // comparisons, so lowercasing would diverge from runtime semantics.
        $fillable = self::filterStringList($instance->getFillable());
        $guarded = self::filterStringList($instance->getGuarded());
        $appends = self::filterStringList($instance->getAppends());
        $hidden = self::filterStringList($instance->getHidden());
        $with = self::readStringList($instance, 'with');
        $withCount = self::readStringList($instance, 'withCount');

        return new ModelMetadata(
            fqcn: $modelFqcn,
            primaryKey: $primaryKey,
            traits: $traits,
            fillable: $fillable,
            guarded: $guarded,
            appends: $appends,
            with: $with,
            withCount: $withCount,
            hidden: $hidden,
            connection: $instance->getConnectionName(),
            morphAlias: self::computeMorphAlias($modelFqcn),
            // Phase 2: ModelMethodHandler / CustomCollectionHandler migration populates these.
            customBuilder: null,
            customCollection: null,
            schemaData: $tableSchema,
            castsData: $casts,
        );
    }

    /**
     * @param class-string<Model> $modelFqcn
     */
    private static function instantiate(string $modelFqcn, Codebase $codebase): ?Model
    {
        try {
            $reflection = new \ReflectionClass($modelFqcn);
            if ($reflection->isAbstract()) {
                return null;
            }

            return $reflection->newInstanceWithoutConstructor();
        } catch (\ReflectionException $reflectionException) {
            // The caller already verified is_a(..., Model::class) + storage presence,
            // so reflection failing here is unexpected — log at debug so --debug runs
            // surface what model lost its metadata and why.
            $codebase->progress->debug(
                "Laravel plugin: ModelMetadataRegistry could not reflect '{$modelFqcn}': {$reflectionException->getMessage()}\n",
            );

            return null;
        }
    }

    private static function computeTraitFlags(ClassLikeStorage $storage, Model $instance): TraitFlags
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
            usesTimestamps: $instance->usesTimestamps(),
        );
    }

    /**
     * Compute primary-key info.
     *
     * HasUuids / HasUlids override `getKeyType()` / `getIncrementing()` / `uniqueIds()`
     * by reading `$this->usesUniqueIds`. The caller in `compute()` has already flipped
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
            $nullable = $column instanceof \Psalm\LaravelPlugin\Providers\ModelMetadata\ColumnInfo && $column->nullable;
            // Preserve original-case column keys to match Eloquent's case-sensitive
            // attribute semantics (callers pass the property name as written).
            $result[$columnName] = self::buildCastInfo($columnName, $castString, $nullable);
        }

        return $result;
    }

    /**
     * Build a {@see CastInfo} from a raw cast string (e.g. `'datetime'`, `'App\\Enums\\Status'`,
     * `'encrypted:array'`, `'App\\Casts\\Money:usd'`).
     *
     * `$nullable` controls only {@see CastInfo::$psalmType} — the discriminator shape is
     * nullability-independent.
     *
     * @param non-empty-string $columnName
     * @param non-empty-string $castString
     */
    private static function buildCastInfo(string $columnName, string $castString, bool $nullable): CastInfo
    {
        [$shape, $targetClass, $parameter] = self::classifyCast($castString);

        return new CastInfo(
            column: $columnName,
            shape: $shape,
            targetClass: $targetClass,
            psalmType: CastResolver::resolve($castString, $nullable),
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
        } elseif ($baseLower === 'collection') {
            $shape = CastShape::AsCollection;
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
            && \is_a($base, \Illuminate\Contracts\Database\Eloquent\CastsAttributes::class, true)
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
            /** @psalm-var mixed $value */
            $value = \constant($constantName);
            if (\is_string($value) && $value !== '') {
                return $value;
            }
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
