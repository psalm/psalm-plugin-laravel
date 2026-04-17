<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers\ModelMetadata;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\Relation;
use Psalm\Type\Union;

/**
 * Resolved description of a single relationship method on a model.
 *
 * @psalm-immutable
 * @psalm-api
 * @internal
 */
final readonly class RelationInfo
{
    /**
     * @param non-empty-lowercase-string                  $name          Normalized relation method name.
     * @param class-string<Relation>                      $relationClass Concrete Relation subclass (HasMany, BelongsTo, ...).
     * @param class-string<Model>|null                    $relatedModel  Null for polymorphic (MorphTo) and dynamically-resolved relations — see #550.
     * @param list<Union>                                 $generics      Resolved generic parameters `[TRelatedModel, TDeclaringModel?, ...]`.
     * @param class-string<Pivot>|null                    $pivotClass    For BelongsToMany / MorphToMany — default {@see Pivot} or the `->using()` override.
     * @param non-empty-string|null                       $pivotAccessor Magic pivot property name on loaded related models; defaults to `'pivot'`.
     */
    public function __construct(
        public string $name,
        public string $relationClass,
        public ?string $relatedModel,
        public array $generics,
        public ?string $pivotClass = null,
        public ?string $pivotAccessor = null,
    ) {}
}
