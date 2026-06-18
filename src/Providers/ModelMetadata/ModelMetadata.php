<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers\ModelMetadata;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable metadata snapshot for a single Eloquent model.
 *
 * {@see \Psalm\LaravelPlugin\Providers\ModelMetadataRegistry} populates all
 * public-readonly fields plus the `schema()`, `casts()`, `accessors()`, `mutators()`,
 * `scopes()`, and `relations()` accessors. All return pre-computed data; lazy memoization was not
 * required because the compute cost per model is small.
 *
 * `knownProperties()` is part of the stable API shape so later handler migrations don't change
 * the contract, but throws {@see \LogicException} until Phase 3 lands.
 *
 * Immutability: every field is `readonly` and the class exposes no setters; the
 * object is fully populated by the builder at construction.
 *
 * @template T of Model
 * @psalm-immutable
 * @psalm-api
 * @internal
 */
final readonly class ModelMetadata
{
    /**
     * Attribute-name fields (`fillable` / `guarded` / `appends` / `hidden`) preserve the
     * exact case the user declared — Eloquent's `isFillable` / `isGuarded` / `getHidden`
     * do case-sensitive string comparisons, and lowercasing would diverge from runtime.
     * Same reason the `castsData` map is keyed by original-case column name.
     *
     * @param class-string<T>                               $fqcn
     * @param list<non-empty-string>                        $fillable
     * @param list<non-empty-string>                        $guarded
     * @param list<non-empty-string>                        $appends
     * @param list<string>                                  $with      Eager-load relation names from `$with`.
     * @param list<string>                                  $withCount Eager-load-count relation names from `$withCount`.
     * @param list<non-empty-string>                        $hidden
     * @param class-string<Builder>|null                    $customBuilder    Detected via #[UseEloquentBuilder] / newEloquentBuilder() / $builder; non-templated because detection cannot recover the model type param.
     * @param class-string<EloquentCollection>|null         $customCollection Detected via #[CollectedBy] / newCollection() / $collectionClass.
     * @param array<non-empty-string, CastInfo>             $castsData Pre-computed cast map (column → CastInfo).
     * @param array<non-empty-lowercase-string, AccessorInfo> $accessorsData Pre-computed accessor map, keyed by snake_case property name; full-callable (self + traits + inherited user ancestors).
     * @param array<non-empty-lowercase-string, MutatorInfo>  $mutatorsData  Pre-computed mutator map, keyed by snake_case property name; the write side of accessors (legacy `setXxxAttribute` may exist write-only).
     * @param array<non-empty-lowercase-string, ScopeInfo>    $scopesData    Pre-computed scope map, keyed by the normalized scope name (`scopePublished`/`#[Scope] published` → `published`); full-callable. Identity only — call-site `self`/`static` pinning stays in {@see \Psalm\LaravelPlugin\Handlers\Eloquent\BuilderScopeHandler}.
     * @param array<non-empty-lowercase-string, RelationInfo> $relationsData Pre-computed relation map, keyed by the lowercased relation method name. OWN-CLASS only (the AST parser resolves a relation factory call only in the receiver's own body), mirroring how the relation handlers call the parser; inherited/trait relations are served by those handlers' `getMethodReturnType` tiers.
     */
    public function __construct(
        public string $fqcn,
        public PrimaryKeyInfo $primaryKey,
        public TraitFlags $traits,
        public array $fillable,
        public array $guarded,
        public array $appends,
        public array $with,
        public array $withCount,
        public array $hidden,
        public ?string $connection,
        public ?string $morphAlias,
        public ?string $customBuilder,
        public ?string $customCollection,
        private TableSchema $schemaData,
        private array $castsData,
        private array $accessorsData,
        private array $mutatorsData,
        private array $scopesData,
        private array $relationsData,
    ) {}

    public function schema(): TableSchema
    {
        return $this->schemaData;
    }

    /**
     * @return array<non-empty-string, CastInfo>  keyed by original-case column name
     */
    public function casts(): array
    {
        return $this->castsData;
    }

    /**
     * Accessor map keyed by snake_case property name. Includes legacy `getXxxAttribute()` and
     * `Attribute::make()`-style accessors declared on the model, its traits, or any inherited
     * user ancestor (full-callable; matches `Codebase::methodExists()` resolution).
     *
     * @return array<non-empty-lowercase-string, AccessorInfo>
     */
    public function accessors(): array
    {
        return $this->accessorsData;
    }

    /**
     * Mutator map keyed by snake_case property name — the write side of {@see accessors()}.
     * A legacy `setXxxAttribute()` may exist without a matching accessor (write-only).
     *
     * @return array<non-empty-lowercase-string, MutatorInfo>
     */
    public function mutators(): array
    {
        return $this->mutatorsData;
    }

    /**
     * Relation map keyed by the lowercased relation method name. Carries the AST-parsed identity
     * (relation class, related model, through-relation intermediate, pivot class/accessor) for every
     * relation factory call found in the model's OWN body. Inherited / trait-hosted relations are not
     * here — the AST parser only resolves the receiver's own body, so the relation handlers keep
     * serving those through their `getMethodReturnType` tiers (this map replaces only their AST-parse
     * tier). `$relatedModel` is null for MorphTo and dynamically-resolved relations (#550).
     *
     * @return array<non-empty-lowercase-string, RelationInfo>
     */
    public function relations(): array
    {
        return $this->relationsData;
    }

    /**
     * Scope map keyed by the normalized scope name — legacy `scopePublished()` and
     * `#[Scope] public function published()` both key as `published`. Full-callable (self +
     * traits + inherited user ancestors), so a scope inherited from an abstract base resolves on
     * the concrete child. Attribute-style wins over a legacy twin of the same name (Laravel's
     * `Model::callNamedScope` precedence). Each {@see ScopeInfo} carries identity only — the
     * declaring {@see \Psalm\Storage\MethodStorage} and the caller-facing params (declared params
     * minus the leading `Builder $query`); call-site `self`/`static` pinning happens in
     * {@see \Psalm\LaravelPlugin\Handlers\Eloquent\BuilderScopeHandler}.
     *
     * @return array<non-empty-lowercase-string, ScopeInfo>
     */
    public function scopes(): array
    {
        return $this->scopesData;
    }

    /**
     * Union of all "known" property names the model exposes, each tagged with
     * its origin(s). Consumed by the #699 unknown-key detector.
     *
     * @return array<non-empty-lowercase-string, PropertyOrigins>
     * @psalm-pure
     * @throws \LogicException until Phase 3 lands — see the ModelMetadataRegistry design doc §7.
     */
    public function knownProperties(): array
    {
        throw new \LogicException(
            'ModelMetadata::knownProperties() is not yet implemented — deferred to Phase 3 of the registry migration.',
        );
    }
}
