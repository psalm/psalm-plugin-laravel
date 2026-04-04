<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Magic;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Defines all known Laravel magic method forwarding patterns as declarative rules.
 *
 * This is the single source of truth for how Laravel's __call/__callStatic forwarding
 * chains are modeled for static analysis. Adding support for a new forwarding pattern
 * means adding a new ForwardingRule entry here — no new handler class required.
 *
 * The configuration mirrors the runtime forwarding chain:
 *
 *     Relation::__call → forwardDecoratedCallTo → Builder
 *     Builder::__call  → forwardCallTo → QueryBuilder; return $this
 *     Model::__call    → forwardCallTo → Builder (pass-through)
 *
 * Each rule maps to one hop in the chain.
 *
 * ## How to add a new forwarding pattern
 *
 * 1. Identify the source class, target class, and forwarding style:
 *    - Decorated: source returns $this when target returns self (uses forwardDecoratedCallTo)
 *    - AlwaysSelf: source always returns $this (discards target's result)
 *    - Passthrough: source returns target's result as-is
 *
 * 2. Add a ForwardingRule:
 *    $registry->register(new ForwardingRule(
 *        sourceClass: YourDecorator::class,
 *        searchClasses: [TargetClass::class],           // where to find the method
 *        style: ForwardingStyle::Decorated,              // how to transform return type
 *        selfReturnIndicators: [TargetClass::class],     // what counts as "returns self"
 *        additionalSourceClasses: [SubclassA::class],    // concrete subclasses
 *        description: 'YourDecorator → TargetClass',
 *    ));
 *
 * 3. Done. MethodForwardingHandler handles the rest.
 */
/** @psalm-external-mutation-free */
final class LaravelForwardingConfig
{
    /**
     * Create a registry with all known Laravel forwarding rules.
     *
     * @psalm-external-mutation-free
     */
    public static function createRegistry(): ForwardingChainRegistry
    {
        $registry = new ForwardingChainRegistry();

        $registry->register(
            self::relationToBuilder(),
            // Future rules can be added here:
            // self::builderToQueryBuilder(),
            // self::modelToBuilder(),
            // self::collectionMacros(),
        );

        return $registry;
    }

    /**
     * Relation → Builder: the decorated forwarding pattern.
     *
     * At runtime, Relation::__call() uses forwardDecoratedCallTo() to proxy
     * methods to the underlying Eloquent\Builder. When the Builder method
     * returns $this (Builder), the Relation returns itself instead, preserving
     * the fluent chain: $post->comments()->where()->orderBy() stays as HasMany.
     *
     * For static analysis: if Builder::where() returns Builder<TModel> (or static),
     * we return HasMany<Comment, Post> (the concrete Relation with its template params).
     * If the method returns something else (e.g., first() → TModel|null), we let
     * Psalm resolve the @mixin type naturally.
     *
     * This replaces RelationsMethodHandler with a declarative configuration.
     *
     * @see \Illuminate\Database\Eloquent\Relations\Relation::__call()
     * @psalm-pure
     */
    private static function relationToBuilder(): ForwardingRule
    {
        return new ForwardingRule(
            sourceClass: Relation::class,
            searchClasses: [Builder::class, QueryBuilder::class],
            style: ForwardingStyle::Decorated,
            // Builder::where() returns Builder<TModel> → treated as "self-returning".
            // Query\Builder methods that return Query\Builder are NOT self-returning —
            // methods like toBase()/getQuery() intentionally drop to the lower layer.
            // is_static return types ($this/static) are detected automatically.
            selfReturnIndicators: [Builder::class],
            // Psalm requires exact class name matching for provider dispatch.
            // List all concrete Relation subclasses so the handler fires for each.
            additionalSourceClasses: [
                BelongsTo::class,
                BelongsToMany::class,
                HasMany::class,
                HasManyThrough::class,
                HasOne::class,
                HasOneOrMany::class,
                HasOneOrManyThrough::class,
                HasOneThrough::class,
                MorphMany::class,
                MorphOne::class,
                MorphOneOrMany::class,
                MorphTo::class,
                MorphToMany::class,
            ],
            // Register this handler for the @mixin target classes (Builder, QueryBuilder)
            // in addition to the Relation source classes. When Psalm resolves a method
            // like where() via @mixin Builder, the return type provider fires for Builder.
            // The handler detects that the caller is a Relation, and applies the
            // Decorated forwarding style — returning HasMany<Comment, Post> instead of
            // Builder<TRelatedModel>.
            interceptMixin: true,
            description: 'Relation → Builder (forwardDecoratedCallTo)',
        );
    }

    // -----------------------------------------------------------------------
    // Future forwarding rules (commented out — to be implemented incrementally)
    // -----------------------------------------------------------------------

    // /**
    //  * Builder → QueryBuilder: always-self forwarding.
    //  *
    //  * Builder::__call() forwards to Query\Builder via forwardCallTo(),
    //  * then unconditionally returns $this. The Query\Builder result is discarded.
    //  *
    //  * Currently handled via @mixin + ModelMethodHandler. Adding this rule would
    //  * let the unified handler cover the Builder → QueryBuilder hop too.
    //  *
    //  * @see \Illuminate\Database\Eloquent\Builder::__call()
    //  */
    // private static function builderToQueryBuilder(): ForwardingRule
    // {
    //     return new ForwardingRule(
    //         sourceClass: Builder::class,
    //         searchClasses: [QueryBuilder::class],
    //         style: ForwardingStyle::AlwaysSelf,
    //         description: 'Builder → QueryBuilder (forwardCallTo + return $this)',
    //     );
    // }

    // /**
    //  * Model → Builder: passthrough static forwarding.
    //  *
    //  * Model::__callStatic() creates a new instance and calls __call(),
    //  * which forwards to newQuery() → Builder. The result is passed through.
    //  *
    //  * Currently handled by ModelMethodHandler. Note: this requires per-model
    //  * registration (AfterCodebasePopulated) because Psalm's provider lookup
    //  * uses exact class names. The unified handler would need a similar
    //  * discovery mechanism for Model subclasses.
    //  */
    // private static function modelToBuilder(): ForwardingRule
    // {
    //     return new ForwardingRule(
    //         sourceClass: Model::class,
    //         searchClasses: [Builder::class, QueryBuilder::class],
    //         style: ForwardingStyle::Passthrough,
    //         description: 'Model → Builder (__callStatic → __call → forwardCallTo)',
    //     );
    // }
}
