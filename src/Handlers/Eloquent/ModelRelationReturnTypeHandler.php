<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Returns the precise generic relation type for user-defined relationship methods on Models.
 *
 * Without this handler, `(new WorkOrder())->invoice()` resolves to `HasOne<Model, Model>`
 * even when the method body is `return $this->hasOne(Invoice::class)` and the docblock
 * says `@psalm-return HasOne<Invoice, $this>`. Two Psalm limitations cause the collapse:
 *
 * 1. The `class-string<TRelatedModel>` argument's TRelatedModel binding is not propagated
 *    to the stub's `@return HasOne<TRelatedModel, $this>` return.
 * 2. `$this` in template position is not substituted with the late-static-bound class.
 *
 * Both collapses happen before any handler registered on the Relation hierarchy can
 * observe a useful generic — the called-on type already arrives at `getRelated()` etc.
 * with `[Model, Model]` template params. Fixing the upstream method's return is the
 * only path that lets the existing stub `@return TRelatedModel` resolve correctly.
 *
 * Strategy: at codebase population time, {@see ModelRegistrationHandler} registers this
 * closure per concrete Model class. For every method call dispatched on the model,
 * {@see RelationMethodParser} parses the AST body to detect a relation factory call
 * (`$this->hasOne(X::class)`, `$this->belongsTo(X::class)`, ...) and returns the
 * properly templated `Relation<TRelatedModel, TDeclaringModel>`. Polymorphic morphTo
 * is intentionally skipped (the related class is determined at runtime). hasOneThrough
 * and hasManyThrough require all three class-strings (related, intermediate, declaring)
 * to resolve statically; if either factory arg is dynamic the handler defers.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/760
 * @internal
 */
final class ModelRelationReturnTypeHandler
{
    /**
     * Memoized return-type Unions keyed by "class::method".
     *
     * The closure is dispatched for every method call on every Model subclass during
     * analysis. RelationMethodParser caches the parsed metadata, but without this
     * second-tier cache we still rebuild the same Union/TGenericObject/TNamedObject
     * graph on every hit. Cache hits return the previously constructed Union directly.
     *
     * Negative results (null) are cached too: methods that turned out not to be a
     * relation factory short-circuit on subsequent dispatches without re-entering
     * the parser at all.
     *
     * @var array<string, ?Union>
     */
    private static array $unionCache = [];

    /**
     * Closure target registered per-class by {@see ModelRegistrationHandler}.
     *
     * Returns null for any method that does not match the relation-factory shape, so
     * other return-type providers downstream (custom collection narrowing, scope
     * proxies, etc.) still get a chance to fire.
     *
     * Not pure: {@see RelationMethodParser::parse} maintains an internal read-through
     * cache, so the call mutates static state on the first hit per (class, method).
     * The Throwable guard is intentionally broad — Psalm's
     * MethodReturnTypeProvider invokes this closure with no top-level catch
     * (vendor/vimeo/psalm/src/Psalm/Internal/Provider/MethodReturnTypeProvider.php),
     * so any escaping exception fatally aborts the whole analysis run. This pattern
     * mirrors the AppFacadeRegistrationHandler closure (see #787).
     */
    public static function getReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $source = $event->getSource();
        if (!$source instanceof StatementsAnalyzer) {
            return null;
        }

        // Two distinct classes matter here. For direct dispatch they're equal; for an
        // inherited method (Psalm 7's "inherited method" branch in MethodCallReturnTypeFetcher),
        // they diverge:
        //
        // - $declaringClass: where the method body actually lives. AST + storage lookups
        //   must use this — asking for `User::posts` when posts is defined on BaseUser
        //   misses storage and the parser returns null.
        // - $bindingClass: the receiver / late-static-bound class. TDeclaringModel
        //   should bind here so that `(new User())->posts()->getParent()` resolves to
        //   User, not BaseUser.
        //
        // Closures are registered per concrete Model class; for the inherited path
        // Psalm dispatches to the closure registered under the *declaring* class
        // ($event->getFqClasslikeName()), so this branch only fires when both
        // declaring and called classes are concrete (registered) Models.
        $declaringClass = $event->getFqClasslikeName();
        $bindingClass = $event->getCalledFqClasslikeName() ?? $declaringClass;
        $methodName = $event->getMethodNameLowercase();

        // Cache keyed by the (declaring, binding) tuple — the Union returned for
        // BaseUser::posts dispatched on User differs from BaseUser::posts dispatched
        // on AdminUser, since each binds a different TDeclaringModel.
        $cacheKey = $declaringClass . '|' . $bindingClass . '::' . $methodName;

        if (\array_key_exists($cacheKey, self::$unionCache)) {
            return self::$unionCache[$cacheKey];
        }

        $codebase = $source->getCodebase();

        try {
            $parsed = RelationMethodParser::parse($codebase, $declaringClass, $methodName);

            $result = $parsed === null
                ? null
                : self::buildRelationType(
                    $parsed['relationClass'],
                    $parsed['relatedModel'],
                    $parsed['intermediateModel'],
                    $bindingClass,
                );
        } catch (\Throwable $throwable) {
            // Plugin closures are invoked by Psalm without a safety net. Surface the
            // failure as a debug message rather than crashing the whole analysis run.
            // The negative result is intentionally NOT cached — a future invocation
            // may succeed (e.g., codebase storage warmed up by another analyzer pass).
            $codebase->progress->debug(
                "Laravel plugin: relation return-type provider failed for {$declaringClass}::{$methodName}: {$throwable->getMessage()}\n",
            );

            return null;
        }

        self::$unionCache[$cacheKey] = $result;

        return $result;
    }

    /**
     * Construct the relation type with the right template-param shape. Returns null when
     * the related model is not statically known (morphTo / dynamic class-string), or when
     * a through relation is missing its intermediate class-string.
     *
     * Two template-param shapes are emitted:
     * - Standard: `Relation<TRelatedModel, TDeclaringModel>`
     * - Through:  `Relation<TRelatedModel, TIntermediateModel, TDeclaringModel>`
     *
     * @param class-string $relationClass
     *
     * @psalm-pure
     */
    private static function buildRelationType(
        string $relationClass,
        ?string $relatedModel,
        ?string $intermediateModel,
        string $declaringClass,
    ): ?Union {
        if ($relatedModel === null) {
            // morphTo (polymorphic) or unresolved class-string arg — leave to default.
            return null;
        }

        $isThrough = $relationClass === HasOneThrough::class || $relationClass === HasManyThrough::class;

        if ($isThrough && $intermediateModel === null) {
            // Through factory called with a dynamic intermediate arg — emitting a 2-param
            // shape would be wrong (the Relation hierarchy expects 3 templates here).
            return null;
        }

        $typeParams = [new Union([new TNamedObject($relatedModel)])];

        if ($intermediateModel !== null) {
            $typeParams[] = new Union([new TNamedObject($intermediateModel)]);
        }

        $typeParams[] = new Union([new TNamedObject($declaringClass)]);

        return new Union([new TGenericObject($relationClass, $typeParams)]);
    }
}
