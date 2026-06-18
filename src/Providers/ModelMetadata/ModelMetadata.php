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
 * public-readonly fields plus the `schema()`, `casts()`, `accessors()`, and `mutators()`
 * accessors. All return pre-computed data; lazy memoization was not required because the
 * compute cost per model is small.
 *
 * The remaining accessors (relations/scopes/knownProperties) are part of the stable API
 * shape so later handler migrations don't change the contract, but throw
 * {@see \LogicException} until their producing phase lands.
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
     * @return array<non-empty-lowercase-string, RelationInfo>
     * @psalm-pure
     * @throws \LogicException until Phase 2 lands.
     */
    public function relations(): array
    {
        throw new \LogicException(
            'ModelMetadata::relations() is not yet implemented — scheduled for Phase 2 of the registry migration.',
        );
    }

    /**
     * @return array<non-empty-lowercase-string, ScopeInfo>
     * @psalm-pure
     * @throws \LogicException until Phase 2 lands.
     */
    public function scopes(): array
    {
        throw new \LogicException(
            'ModelMetadata::scopes() is not yet implemented — scheduled for Phase 2 of the registry migration.',
        );
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
