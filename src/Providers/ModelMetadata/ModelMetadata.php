<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers\ModelMetadata;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable metadata snapshot for a single Eloquent model.
 *
 * Phase 1 ({@see \Psalm\LaravelPlugin\Providers\ModelMetadataRegistry}) populates
 * all public-readonly fields plus the `schema()` and `casts()` accessors consumed
 * by the migrated `ModelPropertyHandler`. Both accessors return pre-computed data;
 * lazy memoization was not required because the compute cost per model is small.
 *
 * The remaining accessors (accessors/mutators/relations/scopes/knownProperties) are
 * part of the stable API shape so Phase-2/3 handler migrations don't change the
 * contract, but throw {@see \LogicException} until their producing phase lands.
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
     * @param class-string<T>                                               $fqcn
     * @param list<non-empty-lowercase-string>                              $fillable
     * @param list<non-empty-lowercase-string>                              $guarded
     * @param list<non-empty-lowercase-string>                              $appends
     * @param list<non-empty-lowercase-string>                              $hidden
     * @param list<string>                                                  $with      Eager-load relation names from `$with`.
     * @param list<string>                                                  $withCount Eager-load-count relation names from `$withCount`.
     * @param class-string<Builder<T>>|null                                 $customBuilder
     * @param class-string<EloquentCollection<int, T>>|null                 $customCollection
     * @param array<non-empty-lowercase-string, CastInfo>                   $castsData Pre-computed cast map (column → CastInfo).
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
    ) {}

    public function schema(): TableSchema
    {
        return $this->schemaData;
    }

    /**
     * @return array<non-empty-lowercase-string, CastInfo>  keyed by lowercased column name
     */
    public function casts(): array
    {
        return $this->castsData;
    }

    /**
     * @return array<non-empty-lowercase-string, AccessorInfo>
     * @psalm-pure
     * @throws \LogicException until Phase 2 lands — see the ModelMetadataRegistry design doc §7.
     */
    public function accessors(): array
    {
        throw new \LogicException(
            'ModelMetadata::accessors() is not yet implemented — scheduled for Phase 2 of the registry migration.',
        );
    }

    /**
     * @return array<non-empty-lowercase-string, MutatorInfo>
     * @psalm-pure
     * @throws \LogicException until Phase 2 lands.
     */
    public function mutators(): array
    {
        throw new \LogicException(
            'ModelMetadata::mutators() is not yet implemented — scheduled for Phase 2 of the registry migration.',
        );
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
