<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\PivotColumnResolver;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Type;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TObjectWithProperties;
use Psalm\Type\Union;

/**
 * Returns the precise generic relation type for user-defined relationship methods on Models.
 *
 * Relationship methods are usually written without a docblock return, or with only a native
 * `: HasOne`, and Psalm types callers from the DECLARED return alone: it never uses the
 * type it infers from the method body. So `(new WorkOrder())->invoice()` with body
 * `return $this->hasOne(Invoice::class)` resolves to `mixed` (no declared return) or to
 * `HasOne<Model, Model>` (native `: HasOne`, template bounds), even though the factory call
 * itself infers `HasOne<Invoice, WorkOrder&static>`. A docblock
 * `@return HasOne<Invoice, $this>` already resolves natively; this handler covers the
 * undocumented majority. It binds the receiver class itself, keeping the `&static` marker
 * when the call is made on `$this` inside a non-final model (#1614).
 *
 * Strategy: at codebase population time, {@see ModelRegistrationHandler} registers this
 * closure per concrete Model class. For every method call dispatched on the model,
 * {@see RelationMethodParser} parses the AST body to detect a relation factory call
 * (`$this->hasOne(X::class)`, `$this->belongsTo(X::class)`, ...) and returns the
 * properly templated concrete relation type (e.g. `HasOne<Invoice, WorkOrder>`,
 * `BelongsToMany<Tag, Post>`, not `Relation<...>`). `MorphTo` is deferred because its
 * target is selected at runtime; Psalm resolves a declared MorphTo generic natively.
 * `HasOneThrough`
 * and `HasManyThrough` require both factory class-string args (related and intermediate)
 * to resolve statically. The declaring-model generic comes from the receiver
 * (`$bindingClass`), not a factory arg, so it is always available; if either factory
 * arg is dynamic the handler defers.
 *
 * For `BelongsToMany` and `MorphToMany` the parser also walks the chain to capture
 * `->using(CustomPivot::class)` (TPivotModel) and `->as('accessor')` (TAccessor) and emits
 * them as the 3rd and 4th template params so downstream pivot intersections (e.g.
 * `first()` returning `TRelatedModel&object{pivot: TPivotModel}`) resolve to the
 * user-declared pivot model rather than the default `Pivot`. When neither mutator is
 * captured the handler still emits all 4 slots, filling slot 3 with the per-relation
 * pivot default (`Pivot` / `MorphPivot`) and slot 4 with `'pivot'` — emitting only 2
 * triggers `MissingTemplateParam` on consumers because Psalm rejects a partial form
 * even when the stub declares a default via `@template T of … = Default` (see #883).
 * Captured `->withPivot()` / `->withTimestamps()` columns refine that default to `Pivot&object{...}` (#1944).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/760
 * @internal
 */
final class ModelRelationReturnTypeHandler
{
    /**
     * Relation classes whose stub declares the 4-template
     * `<TRelatedModel, TDeclaringModel, TPivotModel, TAccessor>` shape, mapped to
     * the stub-declared TPivotModel default. The handler always emits slots 3+4
     * for these relations: when the parser captures `->using(...)` / `->as(...)`
     * the captured values fill the slots, otherwise the per-relation defaults
     * (`Pivot` / `MorphPivot`) and `'pivot'` are emitted (see #883). Other
     * relations ignore the captured pivot/accessor — emitting slots 3+4 on a
     * 2-template relation produces a malformed type. Defaults must match Laravel
     * source: `BelongsToMany` => `Pivot`, `MorphToMany` => `MorphPivot` (see
     * Laravel's MorphToMany constructor and the BelongsToMany stub at
     * stubs/common/Database/Eloquent/Relations/).
     *
     * @var array<class-string, class-string<Pivot>>
     */
    private const PIVOT_AWARE_RELATIONS = [
        BelongsToMany::class => Pivot::class,
        MorphToMany::class => MorphPivot::class,
    ];

    /**
     * Memoized return-type Unions keyed by "declaringClass|bindingClass|static-flag::method".
     *
     * The (declaring, binding) pair distinguishes inherited dispatches: `BaseUser::posts`
     * called on `User` and on `AdminUser` produce different Unions because TDeclaringModel
     * binds to the receiver, not to the class where the method body lives. The static flag
     * keeps `User` (external caller) apart from `User&static` (`$this->posts()` inside a
     * non-final User).
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

    public static function reset(): void
    {
        self::$unionCache = [];
    }

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

        // `$this->posts()` in a non-final model has receiver `User&static`; binding plain `User`
        // is less specific than a declared `HasMany<Post, $this>` (LessSpecificReturnStatement).
        $bindingIsStatic = self::isStaticReceiver($source, $event->getStmt(), $bindingClass);

        $type = self::relationType(
            $source->getCodebase(),
            $declaringClass,
            $bindingClass,
            $methodName,
            $bindingIsStatic,
        );

        // A declared pivot shape is the user's contract, and Psalm keeps it for callers on its own (#1944).
        return $type instanceof Union && self::declaresPivotShape($source->getCodebase(), $declaringClass, $methodName) ? null : $type;
    }

    /** @psalm-capabilities read-props */
    private static function declaresPivotShape(Codebase $codebase, string $declaringClass, string $methodName): bool
    {
        try {
            $methodId = $codebase->methods->getDeclaringMethodId(MethodIdentifier::wrap($declaringClass . '::' . $methodName));
            $declared = $methodId instanceof MethodIdentifier ? $codebase->methods->getStorage($methodId)->return_type : null;
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            return false;
        }

        foreach ($declared?->getAtomicTypes() ?? [] as $atomic) {
            if (!$atomic instanceof TGenericObject || !\is_a($atomic->value, BelongsToMany::class, true)) {
                continue;
            }

            foreach (($atomic->type_params[2] ?? null)?->getAtomicTypes() ?? [] as $pivot) {
                if ($pivot instanceof TObjectWithProperties || ($pivot instanceof TNamedObject && \array_filter(
                    $pivot->extra_types,
                    static fn(Type\Atomic $extra): bool => $extra instanceof TObjectWithProperties,
                ) !== [])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The type a call of the relation method `$methodName` returns when its receiver binds to
     * `$bindingClass`, or null when the method is not a resolvable relation factory. Shared with
     * {@see RelationCallbackParamsHandler}, which needs the Relation an eager-load constraint receives.
     *
     * @internal
     */
    public static function relationType(
        Codebase $codebase,
        string $declaringClass,
        string $bindingClass,
        string $methodName,
        bool $bindingIsStatic,
    ): ?Union {
        // Cache keyed by the (declaring, binding, static) tuple — the Union returned for
        // BaseUser::posts dispatched on User differs from BaseUser::posts dispatched
        // on AdminUser, since each binds a different TDeclaringModel.
        $cacheKey = $declaringClass . '|' . $bindingClass . '|' . ($bindingIsStatic ? 'static' : 'plain') . '::' . $methodName;

        if (\array_key_exists($cacheKey, self::$unionCache)) {
            return self::$unionCache[$cacheKey];
        }

        try {
            $parsed = RelationMethodParser::parse($codebase, $declaringClass, $methodName, $bindingClass);

            if ($parsed === null) {
                $result = null;
            } else {
                $relatedModelType = self::resolveRelatedModelType($parsed);

                $result = $relatedModelType instanceof \Psalm\Type\Union
                    ? self::buildRelationType(
                        $codebase,
                        $parsed['relationClass'],
                        $relatedModelType,
                        $parsed['intermediateModel'],
                        $parsed,
                        $bindingClass,
                        $bindingIsStatic,
                    )
                    : null;

                // A body with a null exit (`if (...) { return; }`) returns null from the call itself.
                if ($result instanceof Union && $parsed['nullable']) {
                    $result = Type::combineUnionTypes($result, Type::getNull());
                }
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
     * Whether the call receiver is exactly the late-static-bound `$bindingClass` (`Class&static`).
     *
     * Psalm dispatches this provider once per receiver atomic but the receiver Union is shared,
     * so a mixed receiver (`T|Class&static`) can't tell which alternative is being resolved.
     * Only a single-atomic receiver is narrowed; anything else keeps the plain class.
     *
     * @psalm-capabilities read-props
     */
    private static function isStaticReceiver(
        StatementsAnalyzer $source,
        MethodCall|StaticCall $stmt,
        string $bindingClass,
    ): bool {
        if (!$stmt instanceof MethodCall) {
            return false;
        }

        $receiverType = $source->getNodeTypeProvider()->getType($stmt->var);
        if (!$receiverType instanceof Union || !$receiverType->isSingle()) {
            return false;
        }

        $atomic = $receiverType->getSingleAtomic();

        return $atomic instanceof TNamedObject
            && $atomic->is_static
            && \strtolower($atomic->value) === \strtolower($bindingClass);
    }

    /**
     * Resolve the related-model Union for the parsed relation. Polymorphic `morphTo`
     * returns null because its target is runtime-selected. Returns null when no statically
     * known target exists.
     *
     * @param array{relationClass: class-string, relatedModel: ?string, intermediateModel: ?string, pivotModel: ?string, accessor: ?string, pivotColumns: list<string>, pivotTable: string|false|null, pivotVisible: bool, nullable: bool} $parsed
     * @psalm-pure
     */
    private static function resolveRelatedModelType(array $parsed): ?Union
    {
        if ($parsed['relatedModel'] !== null) {
            return new Union([new TNamedObject($parsed['relatedModel'])]);
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
     * - BelongsToMany / MorphToMany: always emit the full 4-template form
     *   `<TRelatedModel, TDeclaringModel, TPivotModel, TAccessor>`. When the parser captured
     *   `->using(CustomPivot::class)` / `->as('alias')` chain mutations they fill slots 3 and 4;
     *   otherwise the per-relation pivot default (`Pivot` / `MorphPivot`) and `'pivot'` fill
     *   them. Emitting all 4 slots is required so downstream pivot intersections (e.g.
     *   `first()` returning `TRelatedModel&object{pivot: TPivotModel}`) resolve, and so
     *   consumers don't trip `MissingTemplateParam` on a partial 2-of-4 emission (#883).
     * - Through relations: `<TRelatedModel, TIntermediateModel, TDeclaringModel>` — e.g.
     *   `HasManyThrough<Post, Membership, Country>`. Note the Relation parent's 3rd template
     *   (TResult) is filled implicitly by Psalm via the Through subclass's @template-extends.
     *
     * @param class-string $relationClass
     * @param array{pivotModel: ?string, accessor: ?string, pivotColumns: list<string>, pivotTable: string|false|null, pivotVisible: bool, ...} $pivot
     */
    private static function buildRelationType(
        Codebase $codebase,
        string $relationClass,
        Union $relatedModel,
        ?string $intermediateModel,
        array $pivot,
        string $bindingClass,
        bool $bindingIsStatic,
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
        // the method body lives on a parent class. The `&static` marker is kept when the
        // receiver itself is `static` (`$this->posts()` in a non-final model).
        //
        // The nested `&static` is flagged as already resolved on purpose. Psalm expands the
        // `@return $this` of real methods such as `take()` / `limit()` with the receiver as the
        // static class type, and TypeExpander::expandNamedObject re-binds any unresolved `static`
        // it meets, including the one nested in the generic params. That intersects it with the
        // outer receiver and yields the malformed `Model&Relation<…>` as TDeclaringModel.
        $bindingType = $bindingIsStatic
            ? (new TNamedObject($bindingClass, true))->setIsStatic(true, true)
            : new TNamedObject($bindingClass);
        $typeParams[] = new Union([$bindingType]);

        // Always emit TPivotModel / TAccessor (slots 3 and 4) for pivot-aware relations,
        // filling declared defaults when the parser didn't capture a chain mutation.
        // A partial 2-of-4 emission triggers MissingTemplateParam on consumers (see #883)
        // because once any template is supplied the partial form is rejected even when
        // the stub declares a `@template T of … = Default` expression. The pivot default
        // is per-relation (BelongsToMany => Pivot, MorphToMany => MorphPivot) to match
        // the corresponding stub default.
        $pivotDefault = self::PIVOT_AWARE_RELATIONS[$relationClass] ?? null;
        if ($pivotDefault !== null) {
            $pivotType = new TNamedObject($pivot['pivotModel'] ?? $pivotDefault);
            // A custom pivot class (using()) types its own attributes.
            if ($pivot['pivotModel'] === null && $pivot['pivotColumns'] !== [] && $pivot['pivotTable'] !== false) {
                $pivotType = PivotColumnResolver::refine(
                    $pivotType,
                    PivotColumnResolver::columnTypes($codebase, $pivot['pivotTable'], $pivot['pivotColumns'], $bindingClass, $pivot['pivotVisible']),
                ) ?? $pivotType;
            }

            $typeParams[] = new Union([$pivotType]);
            $typeParams[] = new Union([TLiteralString::make($pivot['accessor'] ?? 'pivot')]);
        }

        return new Union([new TGenericObject($relationClass, $typeParams)]);
    }
}
