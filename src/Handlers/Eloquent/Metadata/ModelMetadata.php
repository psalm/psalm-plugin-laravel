<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Metadata;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\EloquentModelMethods;

/**
 * Immutable metadata snapshot for a single Eloquent model.
 *
 * {@see \Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistry} populates all
 * public-readonly fields plus the `schema()`, `casts()`, `accessors()`, `mutators()`,
 * `scopes()`, `relations()`, and `knownProperties()` accessors. All return pre-computed data; lazy
 * memoization was not required because the compute cost per model is small.
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
    public const SECTION_METHODS = 1 << 0;

    public const SECTION_RELATIONS = 1 << 1;

    public const SECTION_RUNTIME_CONFIGURATION = 1 << 2;

    public const SECTION_SCHEMA = 1 << 3;

    public const SECTION_CASTS = 1 << 4;

    public const SECTION_PRIMARY_KEY = 1 << 5;

    public const ALL_SECTIONS = self::SECTION_METHODS
        | self::SECTION_RELATIONS
        | self::SECTION_RUNTIME_CONFIGURATION
        | self::SECTION_SCHEMA
        | self::SECTION_CASTS
        | self::SECTION_PRIMARY_KEY;

    /**
     * Attribute-name fields (`fillable` / `guarded` / `appends` / `hidden` / `visible`) preserve the
     * exact case the user declared — Eloquent's `isFillable` / `isGuarded` / `getHidden` / `getVisible`
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
     * @param list<non-empty-string>                        $visible   Serialization allow-list from `$visible`; when non-empty it limits `toArray()`/`toJson()` to these keys (consumed by #923).
     * @param class-string<Builder>|null                    $customBuilder    Detected via #[UseEloquentBuilder] / newEloquentBuilder() / $builder; non-templated because detection cannot recover the model type param.
     * @param class-string<EloquentCollection>|null         $customCollection Detected via #[CollectedBy] / newCollection() / $collectionClass.
     * @param array<non-empty-string, CastInfo>             $castsData Pre-computed cast map (column → CastInfo).
     * @param array<non-empty-lowercase-string, AccessorInfo> $accessorsData Pre-computed accessor map, keyed by snake_case property name; full-callable (self + traits + inherited user ancestors).
     * @param array<non-empty-lowercase-string, MutatorInfo>  $mutatorsData  Pre-computed mutator map, keyed by snake_case property name; the write side of accessors (legacy `setXxxAttribute` may exist write-only).
     * @param array<non-empty-lowercase-string, ScopeInfo>    $scopesData    Pre-computed scope map, keyed by the normalized scope name (`scopePublished`/`#[Scope] published` → `published`); full-callable. Identity only — call-site `self`/`static` pinning stays in {@see \Psalm\LaravelPlugin\Handlers\Eloquent\BuilderScopeHandler}.
     * @param array<non-empty-lowercase-string, RelationInfo> $relationsData Pre-computed relation map, keyed by the lowercased relation method name. OWN-CLASS only (the AST parser resolves a relation factory call only in the receiver's own body), mirroring how the relation handlers call the parser; inherited/trait relations are served by those handlers' `getMethodReturnType` tiers.
     * @param array<non-empty-lowercase-string, PropertyOrigins> $knownPropertiesData Pre-computed union of known property names tagged by origin; see {@see knownProperties()}.
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
        public array $visible,
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
        private array $knownPropertiesData,
        private int $completeSections = self::ALL_SECTIONS,
    ) {}

    /**
     * A set bit means the section is authoritative, including when its value is empty. An absent bit
     * means unavailable or failed; build-time failures are reported once by the registry builder.
     */
    public function isComplete(int $requiredSections): bool
    {
        return ($this->completeSections & $requiredSections) === $requiredSections;
    }

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
     * Resolve the accessor backing `$name`, applying {@see accessors()}' keying convention so a caller
     * need not know it: `$name` is normalized through
     * {@see \Psalm\LaravelPlugin\Handlers\Eloquent\Support\EloquentModelMethods::accessorPropertyKey()} (separators stripped,
     * lowercased), so `full_name` / `fullName` / `fullname` all resolve the same accessor. Covers legacy
     * `getXxxAttribute()` and modern `Attribute` accessors alike.
     *
     * @psalm-mutation-free
     */
    public function accessor(string $name): ?AccessorInfo
    {
        $key = EloquentModelMethods::accessorPropertyKey($name);
        if ($key === null) {
            return null;
        }

        return $this->accessorsData[$key] ?? null;
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
     * Union of the "known" property names the model exposes, each tagged with its origin(s) so a
     * consumer decides per context which origins count (the #699 unknown-key detector is the first).
     *
     * Keys are normalized through {@see \Psalm\LaravelPlugin\Handlers\Eloquent\Support\EloquentModelMethods::accessorPropertyKey()}
     * (separators stripped, lowercased), so a `full_name` column and a `fullName()` accessor merge into
     * one `fullname` entry carrying both {@see PropertyOrigin::SchemaColumn} and {@see PropertyOrigin::Accessor}.
     * Sources: schema columns, casts, accessors, mutators (incl. write-only), relations, and `$appends`.
     * `$fillable` / `$guarded` are deliberately NOT sources — they are a guard-list over columns, not an
     * independent supply of attribute names — and docblock `@property` names are not parsed yet.
     *
     * The set is not exhaustive in two known ways, so a consumer must not treat a single model's set as
     * complete: (1) with migrations disabled
     * ({@see \Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaStateProvider::getSchema()} is null) the schema-column
     * origins are absent, so an unknown-key check must not treat the column set as authoritative in that
     * mode (it would flag valid column keys as unknown); (2) the `relations` source is OWN-CLASS only
     * (see {@see relations()}), so a relation inherited from a parent or trait is absent here even though
     * it is a readable property — unlike the accessor / mutator / scope sources, which are full-callable.
     * A consumer needing inherited relations must supplement via the relation handlers' inheritance-aware
     * `getMethodReturnType` tiers.
     *
     * @return array<non-empty-lowercase-string, PropertyOrigins>
     */
    public function knownProperties(): array
    {
        return $this->knownPropertiesData;
    }
}
