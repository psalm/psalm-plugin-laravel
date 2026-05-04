<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TLiteralString;
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
 * properly templated concrete relation type (e.g. `HasOne<Invoice, WorkOrder>`,
 * `BelongsToMany<Tag, Post>`, not `Relation<...>`). `MorphTo` can be narrowed when the
 * related model is statically declared via a docblock generic
 * (`@phpstan-return MorphTo<User|Post, $this>` — read by
 * {@see RelationMethodParser::extractDocblockRelatedModelType}); without that the
 * handler defers because the related class is determined at runtime. `HasOneThrough`
 * and `HasManyThrough` require both factory class-string args (related and intermediate)
 * to resolve statically. The declaring-model generic comes from the receiver
 * (`$bindingClass`), not a factory arg, so it is always available; if either factory
 * arg is dynamic the handler defers.
 *
 * For `BelongsToMany` and `MorphToMany` the parser also walks the chain to capture
 * `->using(CustomPivot::class)` (TPivotModel) and `->as('accessor')` (TAccessor); when
 * present they are emitted as the 3rd and 4th template params so downstream pivot
 * intersections (e.g. `first()` returning `TRelatedModel&object{pivot: TPivotModel}`)
 * resolve to the user-declared pivot model rather than the default `Pivot`. When neither
 * mutator appears the handler emits the 2-param shape and Psalm fills in the template
 * defaults.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/760
 * @internal
 */
final class ModelRelationReturnTypeHandler
{
    /**
     * Relation classes whose stub declares the 4-template
     * `<TRelatedModel, TDeclaringModel, TPivotModel, TAccessor>` shape, mapped to
     * the stub-declared TPivotModel default. The handler emits this default when
     * the parser captures `->as(...)` but no `->using(...)` (the all-or-nothing
     * rule still requires emitting slot 3). Other relations ignore the captured
     * pivot/accessor — emitting slots 3+4 on a 2-template relation produces a
     * malformed type. Defaults must match Laravel source: `BelongsToMany` =>
     * `Pivot`, `MorphToMany` => `MorphPivot` (see Laravel's MorphToMany
     * constructor and the BelongsToMany stub at
     * stubs/common/Database/Eloquent/Relations/).
     *
     * @var array<class-string, class-string<Pivot>>
     */
    private const PIVOT_AWARE_RELATIONS = [
        BelongsToMany::class => Pivot::class,
        MorphToMany::class => MorphPivot::class,
    ];

    /**
     * Memoized return-type Unions keyed by "declaringClass|bindingClass::method".
     *
     * The (declaring, binding) pair distinguishes inherited dispatches: `BaseUser::posts`
     * called on `User` and on `AdminUser` produce different Unions because TDeclaringModel
     * binds to the receiver, not to the class where the method body lives.
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

        // Detect `$this->relation()` (vs `(new Model())->relation()` or `$var->relation()`)
        // so the emitted TDeclaringModel can be `static<bindingClass>` instead of plain
        // `bindingClass`. Required when the receiver is `$this` because user docblocks
        // routinely declare `@return Rel<X, $this>` (= `Rel<X, bindingClass&static>` under
        // psalm/psalm#11768), and a method body like
        // `return $this->users()->where(...)` re-enters this handler with a `$this`
        // receiver — emitting plain `bindingClass` would mismatch the declared
        // `bindingClass&static`. External dispatch like `(new Model())->m()` keeps the
        // plain concrete-class emission so existing tests asserting `Rel<X, ConcreteClass>`
        // (no `&static`) still pass. Covariance on TDeclaringModel in the relation stubs
        // additionally lets the `&static` form satisfy concrete-class declarations like
        // `@return Rel<X, Campaign>` (ixdf-web pattern).
        $stmt = $event->getStmt();
        $isThisDispatch = $stmt instanceof MethodCall
            && $stmt->var instanceof Variable
            && $stmt->var->name === 'this';

        // Cache keyed by the (declaring, binding, isThisDispatch) tuple — the same
        // (declaring, binding) pair can produce two different Unions depending on
        // whether the receiver was `$this` (static-aware) or a concrete instance.
        $cacheKey = $declaringClass . '|' . $bindingClass . '|' . ($isThisDispatch ? '1' : '0') . '::' . $methodName;

        if (\array_key_exists($cacheKey, self::$unionCache)) {
            return self::$unionCache[$cacheKey];
        }

        $codebase = $source->getCodebase();

        try {
            $parsed = RelationMethodParser::parse($codebase, $declaringClass, $methodName);

            if ($parsed === null) {
                $result = null;
            } else {
                $relatedModelType = self::resolveRelatedModelType($parsed, $codebase, $declaringClass, $methodName);

                $result = $relatedModelType instanceof \Psalm\Type\Union
                    ? self::buildRelationType(
                        $parsed['relationClass'],
                        $relatedModelType,
                        $parsed['intermediateModel'],
                        $parsed['pivotModel'],
                        $parsed['accessor'],
                        $bindingClass,
                        $isThisDispatch,
                    )
                    : null;
            }
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
     * Resolve the related-model Union for the parsed relation. Most factories return a
     * single Model FQCN via `relatedModel`; polymorphic `morphTo` returns null there
     * but may declare its target via `@psalm-return MorphTo<User|Post, $this>`, which
     * {@see RelationMethodParser::extractDocblockRelatedModelType} reads from the
     * docblock. Returns null when neither path produces a usable type.
     *
     * @param array{relationClass: class-string, relatedModel: ?string, intermediateModel: ?string, pivotModel: ?string, accessor: ?string} $parsed
     */
    private static function resolveRelatedModelType(array $parsed, Codebase $codebase, string $declaringClass, string $methodName): ?Union
    {
        if ($parsed['relatedModel'] !== null) {
            return new Union([new TNamedObject($parsed['relatedModel'])]);
        }

        // morphTo: the factory's first arg is not a class-string, so the parser yields
        // null. Fall back to the docblock generic for users who annotated their morphTo
        // with the candidate model union.
        if ($parsed['relationClass'] === MorphTo::class) {
            return RelationMethodParser::extractDocblockRelatedModelType($codebase, $declaringClass, $methodName);
        }

        return null;
    }

    /**
     * Construct the relation type with the right template-param shape. Returns null when
     * a through relation is missing its intermediate class-string.
     *
     * The returned Union wraps a `TGenericObject` for the concrete relation class named by
     * `$relationClass`. The shape varies per Relation subclass:
     * - Standard relations: `<TRelatedModel, TDeclaringModel>` — e.g. `HasOne<Post, User>`,
     *   `BelongsTo<User, Post>`.
     * - BelongsToMany / MorphToMany: `<TRelatedModel, TDeclaringModel>` by default, expanding
     *   to `<TRelatedModel, TDeclaringModel, TPivotModel, TAccessor>` when the parser captured
     *   `->using(CustomPivot::class)` / `->as('alias')` chain mutations. The 4-template form
     *   is needed so downstream pivot intersections (e.g. `first()` returning
     *   `TRelatedModel&object{pivot: TPivotModel}`) resolve to the user-declared pivot model
     *   rather than the default `Pivot`.
     * - Through relations: `<TRelatedModel, TIntermediateModel, TDeclaringModel>` — e.g.
     *   `HasManyThrough<Post, Membership, Country>`. Note the Relation parent's 3rd template
     *   (TResult) is filled implicitly by Psalm via the Through subclass's @template-extends.
     *
     * @param class-string $relationClass
     *
     * @psalm-pure
     */
    private static function buildRelationType(
        string $relationClass,
        Union $relatedModel,
        ?string $intermediateModel,
        ?string $pivotModel,
        ?string $accessor,
        string $bindingClass,
        bool $isThisDispatch,
    ): ?Union {
        $isThrough = $relationClass === HasOneThrough::class || $relationClass === HasManyThrough::class;

        if ($isThrough && $intermediateModel === null) {
            // Through factory called with a dynamic intermediate arg — emitting a 2-param
            // shape would be wrong (the Relation hierarchy expects 3 templates here).
            return null;
        }

        $typeParams = [$relatedModel];

        if ($intermediateModel !== null) {
            $typeParams[] = new Union([new TNamedObject($intermediateModel)]);
        }

        // $bindingClass is the late-static-bound receiver class — what TDeclaringModel
        // should resolve to at the call site (User for `(new User())->posts()`), even if
        // the method body lives on a parent class. When dispatched on `$this`, mark the
        // type as static (`bindingClass&static`) so it matches `$this` in user docblocks
        // declaring `@return Rel<X, $this>`. External dispatch keeps the plain concrete
        // class so `(new User())->posts()` still resolves to `Rel<X, User>` (no `&static`).
        $typeParams[] = new Union([new TNamedObject($bindingClass, is_static: $isThisDispatch)]);

        // Emit TPivotModel / TAccessor (slots 3 and 4) only when the parser captured a
        // chain mutation. Both slots are filled together (with declared defaults filling
        // any gap); a partial 3-template `BelongsToMany<R, D, P>` would leave TAccessor
        // unbound on a relation whose template list expects 4 entries when any are given.
        // The pivot default is per-relation (BelongsToMany => Pivot, MorphToMany => MorphPivot)
        // to match the stub's @template default expression.
        $pivotDefault = self::PIVOT_AWARE_RELATIONS[$relationClass] ?? null;
        if ($pivotDefault !== null && ($pivotModel !== null || $accessor !== null)) {
            $typeParams[] = new Union([new TNamedObject($pivotModel ?? $pivotDefault)]);
            $typeParams[] = new Union([TLiteralString::make($accessor ?? 'pivot')]);
        }

        return new Union([new TGenericObject($relationClass, $typeParams)]);
    }
}
