<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelRelationReturnTypeHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\RelationMethodParser;
use Psalm\LaravelPlugin\Internal\ClassLineage;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * What one relation method of a model is, for
 * {@see \Psalm\LaravelPlugin\Handlers\Eloquent\RelationCallbackParamsHandler}: one descriptor per (model, segment) in
 * place of separate class, type and related-model lookups. {@see of()} returns null when the segment names no single
 * Relation class (unknown method, or a declared union of Relations: `HasMany<A>|HasOne<B>`).
 *
 * Precedence: the parsed factory call (read from the method's appearing class, bound to the receiver) answers first,
 * also for a relation inherited from a parent or hosted by a trait; only when the body is unparseable does the
 * declared generic answer, through {@see RelationResolver}. `$relatedModel` is null when no single model is known:
 * a MorphTo, an unresolvable or non-model target, or a parsed model that is a proper ancestor of the model the
 * relation is read on (the parser pins `static::class` to the declaring class, so that may be a leak, however the
 * relation is reached; `self::class` declines too). `$type` is the exact parsed Relation type, which only the
 * factory-call parser can give (null: no parseable factory call).
 *
 * @internal
 */
final class RelationFacts
{
    /**
     * @param class-string<Model>|null $relatedModel
     * @psalm-capabilities read-props
     */
    private function __construct(
        public readonly ?string $relatedModel,
        public readonly ?TGenericObject $type,
        public readonly bool $isMorphTo,
    ) {}

    public static function of(Codebase $codebase, string $model, string $segment): ?self
    {
        $type = self::parsedType($codebase, $model, $segment);
        $class = self::relationClass($codebase, $model, $segment, $type);

        if ($class === null) {
            return null;
        }

        $related = $type instanceof TGenericObject
            ? ModelPropertyResolver::extractExactlyOneModelFromUnion($type->type_params[0] ?? null, $codebase)
            : RelationResolver::relatedModel($codebase, $model, $segment);

        if ($related === null
            || !ClassLineage::isA($codebase, $related, Model::class)
            || ($type instanceof TGenericObject && \strcasecmp($related, $model) !== 0 && ClassLineage::isA($codebase, $model, $related))
        ) {
            $related = null;
        }

        return new self($related, $type, ClassLineage::isA($codebase, $class, MorphTo::class));
    }

    /**
     * The Relation class of a relation method: the parsed call's (`$type`), else the declared native or docblock
     * return type, which a `morphTo()` has no related model for. A declared union of Relations
     * (`HasMany<A>|HasOne<B>`) names no single class or model: null.
     */
    private static function relationClass(Codebase $codebase, string $model, string $method, ?TGenericObject $type): ?string
    {
        if ($type instanceof TGenericObject) {
            return $type->value;
        }

        $methodId = \strtolower($method);
        $selfClass = $model;

        try {
            $declared = $codebase->getMethodReturnType($model . '::' . $methodId, $selfClass);
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            return null;
        }

        $relations = [];
        foreach ($declared instanceof Union ? $declared->getAtomicTypes() : [] as $atomic) {
            if ($atomic instanceof TNamedObject && ClassLineage::isA($codebase, $atomic->value, Relation::class)) {
                $relations[] = $atomic->value;
            }
        }

        if (\count($relations) > 1) {
            return null;
        }

        return RelationMethodParser::parse($codebase, $model, $methodId)['relationClass'] ?? $relations[0] ?? null;
    }

    /**
     * The type the plugin's return provider gives a call of the relation method (null: no parseable factory
     * call). The method's APPEARING class is the one that reads the body: a trait-hosted relation has none of
     * its own storage and binds `self` to the class that composes the trait.
     */
    private static function parsedType(Codebase $codebase, string $model, string $method): ?TGenericObject
    {
        $methodId = \strtolower($method);

        try {
            $appearing = $codebase->methods->getAppearingMethodId(MethodIdentifier::wrap($model . '::' . $methodId))->fq_class_name ?? null;
        } catch (\InvalidArgumentException|\UnexpectedValueException|UnpopulatedClasslikeException) {
            return null;
        }

        $type = $appearing === null ? null : ModelRelationReturnTypeHandler::relationType($codebase, $appearing, $model, $methodId, false);

        foreach ($type instanceof Union ? $type->getAtomicTypes() : [] as $atomic) {
            if ($atomic instanceof TGenericObject && ClassLineage::isA($codebase, $atomic->value, Relation::class)) {
                return $atomic;
            }
        }

        return null;
    }
}
