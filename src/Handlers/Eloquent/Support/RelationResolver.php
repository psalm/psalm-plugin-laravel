<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Handlers\Eloquent\RelationMethodParser;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Shared relation lookups for the relation-name validation rule (`with()`,
 * `load()`, `has()`, `whereHas()`, ...). See {@see \Psalm\LaravelPlugin\Handlers\Rules\UndefinedModelRelationHandler}.
 *
 * Two distinct questions are answered here:
 *
 *  - {@see relationMethodExists()} — "is there a method with this name at all?".
 *    This is intentionally an *existence* check, not an *is-a-relation* check:
 *    the rule only flags relation names that resolve to nothing (the typo case
 *    the issue targets), and stays silent when a method exists but is not a
 *    relation. That keeps false positives near zero on real apps where relations
 *    can be added in ways static analysis cannot see (runtime macros, packages).
 *
 *  - {@see relatedModel()} — the target model of a relation, used to walk
 *    dot-notation chains (`with('posts.comments')`). Null means "cannot resolve
 *    deeper" (polymorphic `morphTo`, dynamic class-string argument, or no
 *    declared generic), and the caller stops validating the remaining segments.
 *
 * This is a separate predicate from {@see \Psalm\LaravelPlugin\Handlers\Eloquent\ModelRelationshipPropertyHandler}'s
 * private `relationExists()`, which is *type-aware* (it must confirm a Relation
 * return type before providing a magic-property type). The two are not the same
 * function and deliberately not merged: that handler is a cache-coupled hot path.
 *
 * @internal
 */
final class RelationResolver
{
    /** @var array<string, bool> Cache for relationMethodExists() keyed by "class::lowername" */
    private static array $methodExistsCache = [];

    /** @var array<string, ?string> Cache for relatedModel() keyed by "class::lowername" */
    private static array $relatedModelCache = [];

    /** @psalm-external-mutation-free */
    public static function reset(): void
    {
        self::$methodExistsCache = [];
        self::$relatedModelCache = [];
    }

    /**
     * Whether a method with this name exists on the model — either a real
     * method (including inherited / trait methods) or a `@method` pseudo-method.
     *
     * The lookup is case-insensitive because PHP method dispatch is: Laravel
     * resolves `with('Posts')` to the `posts()` method at runtime, so a
     * capitalized relation name must not be reported as undefined.
     */
    public static function relationMethodExists(Codebase $codebase, string $modelFqcn, string $relationName): bool
    {
        $methodId = $modelFqcn . '::' . \strtolower($relationName);

        if (\array_key_exists($methodId, self::$methodExistsCache)) {
            return self::$methodExistsCache[$methodId];
        }

        // with_pseudo: true covers both real (declaring_method_ids) and
        // `@method`-declared (declaring_pseudo_method_ids) relation methods, so
        // a single call answers the existence question for both forms.
        // is_used: false keeps this an existence probe — it must not mark the
        // relation method as used and skew unused-code analysis.
        // Psalm 6's public Codebase::methodExists() has no with_pseudo param and
        // always calls through with it false, unlike Psalm 7 — bypass to the
        // internal Methods::methodExists() to reach the pseudo-inclusive form.
        $exists = $codebase->methods->methodExists(
            new MethodIdentifier($modelFqcn, \strtolower($relationName)),
            is_used: false,
            with_pseudo: true,
        );
        self::$methodExistsCache[$methodId] = $exists;

        return $exists;
    }

    /**
     * Resolve the related model FQCN of a relation, for walking dot-notation
     * chains. Returns null when the target cannot be statically determined
     * (polymorphic `morphTo`, dynamic class-string argument, or a relation with
     * no parseable body and no generic return type).
     *
     * @return ?string FQCN of the related model
     */
    public static function relatedModel(Codebase $codebase, string $modelFqcn, string $relationName): ?string
    {
        $methodId = $modelFqcn . '::' . \strtolower($relationName);

        if (\array_key_exists($methodId, self::$relatedModelCache)) {
            return self::$relatedModelCache[$methodId];
        }

        $related = self::resolveRelatedModel($codebase, $modelFqcn, $relationName);
        self::$relatedModelCache[$methodId] = $related;

        return $related;
    }

    private static function resolveRelatedModel(Codebase $codebase, string $modelFqcn, string $relationName): ?string
    {
        // Tier 1: parse the relationship method body for the related class-string
        // argument (e.g. $this->hasMany(Comment::class)). Covers user models with
        // or without a declared return type.
        $parsed = RelationMethodParser::parse($codebase, $modelFqcn, $relationName);
        if ($parsed !== null && $parsed['relatedModel'] !== null) {
            return $parsed['relatedModel'];
        }

        // Tier 2: read the first generic parameter of the declared return type
        // (e.g. @return HasMany<Comment, Post>). Covers stubbed relations and
        // relations annotated with generics but no parseable factory call.
        $selfClass = $modelFqcn;
        try {
            $returnType = $codebase->getMethodReturnType($modelFqcn . '::' . \strtolower($relationName), $selfClass);
        } catch (\InvalidArgumentException|\UnexpectedValueException $e) {
            // getMethodReturnType() reaches Methods::getStorage(), which throws
            // UnexpectedValueException on a storage inconsistency (e.g. missing
            // declaring/appearing storage) in addition to InvalidArgumentException.
            // Catch both so a resolution failure defers instead of aborting the run,
            // matching RelationMethodParser's storage-access catch.
            $codebase->progress->debug(
                "Laravel plugin: could not get return type for {$modelFqcn}::{$relationName}: {$e->getMessage()}\n",
            );
            return null;
        }

        return self::extractRelatedFromReturnType($codebase, $returnType);
    }

    /**
     * Extract the related model FQCN from the first generic parameter of a
     * `Relation<TRelated, ...>` return type.
     */
    private static function extractRelatedFromReturnType(Codebase $codebase, ?Union $returnType): ?string
    {
        if (!$returnType instanceof Union) {
            return null;
        }

        foreach ($returnType->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TGenericObject || !self::isClassOrSubclassOf($codebase, $atomic->value, Relation::class)) {
                continue;
            }

            return self::singleModel($codebase, $atomic->type_params[0] ?? null);
        }

        return null;
    }

    /**
     * The model FQCN when the type resolves to exactly one model, else null. A
     * multi-model union is polymorphic (a `morphTo`'s TRelated, e.g.
     * `MorphTo<Vehicle|WorkOrder, $this>`) and cannot be pinned to one target for
     * dot-notation walking, so it defers rather than guessing one arm. In practice
     * Psalm collapses morphTo's `<..., $this>` generic before this is reached; this
     * keeps the deferral correct even when it does not.
     */
    private static function singleModel(Codebase $codebase, ?Union $type): ?string
    {
        if (!$type instanceof Union) {
            return null;
        }

        $model = null;

        foreach ($type->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject || !self::isClassOrSubclassOf($codebase, $atomic->value, Model::class)) {
                continue;
            }

            if ($model !== null && \strtolower($model) !== \strtolower($atomic->value)) {
                return null;
            }

            $model = $atomic->value;
        }

        return $model;
    }

    /**
     * $class is $ancestor or a subclass, without autoloading. Unlike `\is_a($class, ..., true)`, which
     * loads the class file: a related model FQCN taken from a relation's generic return type could emit
     * a load-time deprecation that Psalm's error handler turns into a thrown exception, crashing the
     * whole run (the #1253 bug class, which the sibling {@see \Psalm\LaravelPlugin\Handlers\Rules\UndefinedModelRelationHandler}
     * fixed for its own sites but not for this resolver it delegates to). Mirrors that handler's own
     * non-autoloading check. classExtends() is non-reflexive → identity is checked first; every ancestor
     * passed here (Relation, Model) is a class, so classExtends() alone suffices.
     *
     * Not marked mutation-free: Psalm 6's Codebase::classExists()/classExtends()
     * are not annotated mutation-free, unlike Psalm 7.
     */
    private static function isClassOrSubclassOf(Codebase $codebase, string $class, string $ancestor): bool
    {
        if (\strtolower($class) === \strtolower($ancestor)) {
            return true;
        }

        if (!$codebase->classExists($class)) {
            return false;
        }

        try {
            return $codebase->classExtends($class, $ancestor);
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return false;
        }
    }
}
