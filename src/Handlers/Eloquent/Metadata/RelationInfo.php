<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Metadata;

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
     * Mirrors {@see \Psalm\LaravelPlugin\Handlers\Eloquent\RelationMethodParser::parse()} identity:
     * the AST-derived relation class, related model, through-relation intermediate, and any
     * `->using()` / `->as()` pivot mutation. `$generics` stays empty in Phase 2c — the call-site
     * generic resolution (`$this`/`static` declaring-model substitution) remains in the return-type
     * handler, just as scope `self`/`static` pinning stayed in the builder-scope handler.
     *
     * FQCN fields are the raw class-string arguments the AST parser read from the relation factory
     * call ({@see \Psalm\LaravelPlugin\Handlers\Eloquent\RelationMethodParser}); they are not verified
     * to be `Model` / `Pivot` subclasses (the parser does not load them), so they stay plain `?string`
     * — exactly the contract the relation handlers consume.
     *
     * @param non-empty-lowercase-string                  $name              Normalized relation method name.
     * @param class-string<Relation>                      $relationClass     Concrete Relation subclass (HasMany, BelongsTo, ...).
     * @param string|null                                 $relatedModel      Related-model FQCN; null for polymorphic (MorphTo) and dynamically-resolved relations (#550) or a non-literal first argument.
     * @param list<Union>                                 $generics          Resolved generic parameters `[TRelatedModel, TDeclaringModel?, ...]`; empty until a consumer needs them.
     * @param string|null                                 $intermediateModel Non-null only for hasOneThrough / hasManyThrough (the second class-string argument).
     * @param string|null                                 $pivotClass        For BelongsToMany / MorphToMany — the `->using()` override, when statically resolvable.
     * @param string|null                                 $pivotAccessor     The `->as('alias')` override for the magic pivot property name, when statically resolvable.
     */
    public function __construct(
        public string $name,
        public string $relationClass,
        public ?string $relatedModel,
        public array $generics,
        public ?string $intermediateModel = null,
        public ?string $pivotClass = null,
        public ?string $pivotAccessor = null,
    ) {}
}
