<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\Type\TypeExpander;
use Psalm\LaravelPlugin\Util\EloquentModelMethods;
use Psalm\LaravelPlugin\Util\ModelPropertyResolver;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodParamsProviderInterface;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\StatementsSource;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Handles scope and trait-declared method resolution on Eloquent Builder instances.
 *
 * When a method call on Builder doesn't match a real method, checks the model for:
 * 1. Legacy scopeXxx() methods (e.g., scopeActive → active())
 * 2. Methods with #[Scope] attribute (e.g., #[Scope] active() → active())
 * 3. Trait-declared builder methods (e.g., SoftDeletes::withTrashed → withTrashed())
 *    These are registered as Builder macros at runtime via global scopes
 *    (e.g., SoftDeletingScope::extend), and declared via @method static returning
 *    Builder<static> on the model trait.
 * 4. @method PHPDoc scopes are handled natively by Psalm
 *
 * @internal
 */
final class BuilderScopeHandler implements MethodReturnTypeProviderInterface, MethodParamsProviderInterface
{
    /**
     * Cache for isTraitBuilderMethod results.
     *
     * Separate from {@see $scopeParamsCache} to keep concerns distinct.
     *
     * @var array<string, bool>
     */
    private static array $traitBuilderCache = [];

    /**
     * Scope hand-off: lowercase scope name -> the model FQCN whose scope was resolved.
     *
     * Populated by the return type provider when it resolves an instance scope call
     * (Customer::query()->active()); consumed by {@see getMethodParams} when Psalm
     * immediately follows up with checkMethodArgs for the same call. Keyed by method name
     * only: the producer always runs right before the consumer within one analysis thread
     * (MissingMethodCallHandler -> checkMethodArgs is the lone follow-up), so the latest
     * entry matches the call being checked even when two models declare a scope with the
     * same name. The consumer {@see \unset()}s the entry immediately after reading it, so a
     * stale entry can never shadow a later call — which is why this stores the model class
     * and re-resolves params through the memoized {@see getScopeParams} rather than caching
     * a param list (mirrors {@see \Psalm\LaravelPlugin\Handlers\Magic\MethodForwardingHandler}).
     *
     * @var array<lowercase-string, class-string<Model>>
     */
    private static array $pendingScopeModel = [];

    /**
     * Memoization for {@see getScopeParams}: 'Model::method' -> params or null (not a scope).
     *
     * @var array<string, list<FunctionLikeParameter>|null>
     */
    private static array $scopeParamsCache = [];

    /**
     * Registry of trait-declared builder methods for the base Builder class.
     *
     * Populated by {@see ModelRegistrationHandler} when processing base-Builder models
     * (models that don't declare a custom builder). Keyed by lowercase method name.
     * Since trait methods (e.g., SoftDeletes::withTrashed) have consistent signatures
     * across all models, the first registration per method name is authoritative.
     *
     * Used by both the return type provider and the params provider so that Psalm can:
     * - Infer the correct return type (Builder<TModel>) for these method calls
     * - Validate call arguments without crashing on missing method params
     *
     * @var array<lowercase-string, list<FunctionLikeParameter>>
     */
    private static array $baseBuilderTraitMethods = [];

    /**
     * Register trait-declared builder methods discovered on a base-Builder model.
     *
     * Called by {@see ModelRegistrationHandler} after extracting @method static annotations
     * that return Builder<static> from a model's pseudo_static_methods. The first registration
     * per method name wins, since SoftDeletes' signatures are consistent across all models.
     *
     * @param array<lowercase-string, list<FunctionLikeParameter>> $methods
     * @psalm-external-mutation-free
     */
    public static function registerBaseBuilderTraitMethods(array $methods): void
    {
        // array_merge would re-index; += preserves keys and keeps the first registration.
        self::$baseBuilderTraitMethods += $methods;
    }

    /**
     * Reset all mutable per-process state. Called once per Plugin::__construct so a re-bootstrap
     * (a config flip across analysis runs, or a test fixture re-booting the plugin) starts from a
     * clean slate instead of inheriting the previous run's caches and registrations.
     *
     * $pendingScopeModel in particular is a short-lived producer->consumer hand-off; clearing it
     * here guarantees no entry survives into a fresh run even if a producer write went unconsumed.
     *
     * @psalm-external-mutation-free
     */
    public static function init(): void
    {
        self::$pendingScopeModel = [];
        self::$scopeParamsCache = [];
        self::$traitBuilderCache = [];
        self::$baseBuilderTraitMethods = [];
    }

    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [Builder::class];
    }

    /**
     * Provide return types for scope and trait-declared builder method calls on Builder instances.
     *
     * Scope methods (via hasScopeMethod) need template params to identify the model — these
     * are passed when Psalm resolves a known method, but NOT when routing through __call.
     * Trait-declared builder methods (e.g., withTrashed) go through __call at runtime, so
     * template params are absent; we recover them from the LHS expression type instead.
     */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $methodName = $event->getMethodNameLowercase();
        $source = $event->getSource();
        $codebase = $source->getCodebase();

        // Template params are provided when Psalm resolves a known Builder method via
        // MethodCallReturnTypeFetcher (e.g., where(), get()). For magic __call routing
        // (scope/trait methods), MissingMethodCallHandler calls the return type provider
        // without forwarding the LHS type params. We recover them from the LHS expression.
        $templateTypeParameters = $event->getTemplateTypeParameters();

        // For scope and trait-declared builder methods (e.g., active(), withTrashed()),
        // extract from LHS when template params are missing. Builder::methodName has no
        // real method storage, so when Psalm follows up with checkMethodArgs, the params
        // provider below must answer for the method (scope params come from the
        // scope-params hand-off populated in the scope branch).
        if ($templateTypeParameters === null) {
            $templateTypeParameters = self::extractTemplateParamsFromCallStmt($event->getStmt(), $source);
        }

        // Builder<TModel> — TModel is the first template param
        $modelClass = ModelPropertyResolver::extractModelFromUnion($templateTypeParameters[0] ?? null);
        if ($modelClass === null) {
            return null;
        }

        $builderReturn = new Union([
            new TGenericObject(Builder::class, [
                new Union([new TNamedObject($modelClass)]),
            ]),
        ]);

        // A non-null getScopeParams() implies the model declares the scope (detection is
        // strict, see getScopeParams). On failure to resolve we decline entirely — falling
        // back to mixed is honest, while returning a type with a fabricated zero-param
        // signature would emit TooManyArguments on every scope call with args.
        //
        // EXCEPT when the bare name is a real Eloquent\Builder method (find, latest): Psalm
        // resolves those natively and Laravel's Builder::__call never fires for a declared
        // name, so a like-named scope cannot shadow it at runtime. Returning the scope's
        // Builder<TModel> here would wrongly mask the real return type (find -> TModel|null;
        // issue #1039). Names that route through __call STAY scope-eligible: __call checks
        // hasNamedScope before the $passthru aggregate forward (count, sum, exists) and before
        // forwarding to Query\Builder, so the scope legitimately wins there (the classic
        // scopeCount() footgun).
        //
        // (isRealPublicBuilderMethod classifies via PHP reflection, not Psalm storage — see its
        // docblock for why the stub's $passthru aggregates force that distinction.)
        if (
            self::getScopeParams($codebase, $modelClass, $methodName) !== null
            && !self::isRealPublicBuilderMethod($methodName)
        ) {
            // Hand the model to the params provider, consumed once (see $pendingScopeModel):
            // Psalm follows this return type with checkMethodArgs for Builder::<scope>, which
            // has no real method storage and would otherwise throw UnexpectedValueException in
            // Codebase\Methods::getMethodParams.
            self::$pendingScopeModel[$methodName] = $modelClass;

            // A value-returning scope surfaces its declared return via Laravel's `?? $this`
            // coalesce; a plain void/fluent scope keeps $builderReturn unchanged (issue #1053).
            return self::forwardedScopeReturnType($codebase, $modelClass, $methodName, $builderReturn);
        }

        // Trait-declared builder methods (e.g., SoftDeletes::withTrashed) are registered
        // as Builder macros at runtime via global scopes (e.g., SoftDeletingScope::extend).
        // For base-Builder models, these @method static annotations remain in
        // pseudo_static_methods and are handled here. Models with custom builders have
        // these removed from pseudo_static_methods by ModelRegistrationHandler and handled
        // by CustomBuilderMethodHandler instead.
        // See https://github.com/psalm/psalm-plugin-laravel/issues/635
        if (self::isTraitBuilderMethod($codebase, $modelClass, $methodName)) {
            return $builderReturn;
        }

        return null;
    }

    /**
     * Provide params for trait-declared builder method calls on Builder instances.
     *
     * When the return type provider returns a non-null type for a magic __call route,
     * Psalm calls checkMethodArgs with the method identifier. Since Builder::withTrashed
     * doesn't exist as a real method, getMethodParams would throw UnexpectedValueException
     * unless we intercept it here with the params from the trait's @method static annotation.
     *
     * Covers instance scope calls via the {@see $pendingScopeModel} hand-off populated
     * by the return type provider, then $baseBuilderTraitMethods (e.g., SoftDeletes
     * methods) — mirroring the precedence in {@see getMethodReturnType} (scopes first).
     *
     * @return list<FunctionLikeParameter>|null
     */
    #[\Override]
    public static function getMethodParams(MethodParamsProviderEvent $event): ?array
    {
        /** @var lowercase-string $methodName */
        $methodName = $event->getMethodNameLowercase();

        // Instance scope hand-off (see $pendingScopeModel), consumed once: re-resolve the
        // scope's params (minus the leading $query) through the memoized getScopeParams and
        // unset the entry so a stale value can never shadow a later call. No isRealBuilderMethod
        // guard is needed: the producer already excluded real Eloquent\Builder methods, and
        // consume-once prevents cross-call leaks.
        $modelClass = self::$pendingScopeModel[$methodName] ?? null;
        if ($modelClass !== null) {
            unset(self::$pendingScopeModel[$methodName]);

            $source = $event->getStatementsSource();
            if ($source instanceof StatementsSource) {
                $scopeParams = self::getScopeParams($source->getCodebase(), $modelClass, $methodName);
                if ($scopeParams !== null) {
                    return $scopeParams;
                }
            }
        }

        return self::$baseBuilderTraitMethods[$methodName] ?? null;
    }

    /**
     * Whether $methodName is a real PUBLIC method on the actual Eloquent\Builder class.
     *
     * Real public methods (find, latest, where, get, ...) are invoked directly by PHP, so
     * Builder::__call never fires and a like-named scope is dead code — the producer skips them.
     * The check uses runtime reflection on the loaded core Builder class rather than Psalm storage,
     * because the stub declares the $passthru aggregates (count, sum) on Eloquent\Builder for
     * typing while Laravel routes them through __call; only PHP's view tells them apart. It runs
     * gated behind a confirmed scope, so only the rare scope-vs-real-method collision pays for it.
     *
     * The PUBLIC gate matters: Builder's protected helpers (callScope, forwardCallTo) are reachable
     * from outside only via __call, so a like-named scope could legitimately shadow them — they
     * must stay scope-eligible, not be skipped.
     */
    private static function isRealPublicBuilderMethod(string $methodName): bool
    {
        if (!\method_exists(Builder::class, $methodName)) {
            return false;
        }

        try {
            return (new \ReflectionMethod(Builder::class, $methodName))->isPublic();
        } catch (\ReflectionException) {
            return false;
        }
    }

    /**
     * Get the params a caller passes to a scope (its declared params minus the
     * leading $query, which Laravel injects), or null when the model declares no
     * such scope.
     *
     * Dispatch mirrors Laravel's Model::callNamedScope: a #[Scope]-attributed
     * method wins over a legacy scopeXxx() method of the same name. Detection is
     * strict (bare methods require the attribute), so a non-null return implies
     * the method is a real scope — callers don't need a separate hasScopeMethod()
     * guard.
     *
     * Memoized: deterministic per model+method once the codebase is populated.
     *
     * @param class-string<Model> $modelClass
     * @return list<FunctionLikeParameter>|null
     */
    public static function getScopeParams(Codebase $codebase, string $modelClass, string $methodName): ?array
    {
        // Normalize once: Psalm lowercases method names before invoking providers, but external
        // callers (ModelMethodHandler, MethodForwardingHandler) may pass any casing. Lowercasing
        // here de-fragments the cache key and lets the MethodIdentifier constructor accept it.
        $methodName = \strtolower($methodName);

        $key = $modelClass . '::' . $methodName;
        if (\array_key_exists($key, self::$scopeParamsCache)) {
            return self::$scopeParamsCache[$key];
        }

        $scopeMethodId = self::resolveScopeMethodId($codebase, $modelClass, $methodName);
        if (!$scopeMethodId instanceof \Psalm\Internal\MethodIdentifier) {
            return self::$scopeParamsCache[$key] = null;
        }

        // Methods::getMethodParams resolves the declaring class internally, so scopes
        // declared on abstract parent models or in traits keep their signatures. Drop the
        // leading $query param; the caller passes everything after it.
        $callerParams = \array_slice($codebase->methods->getMethodParams($scopeMethodId), 1);

        // `self` in a trait- or parent-hosted scope follows PHP trait semantics: it binds to
        // the class that *composes* the scope method (its appearing class), not the subclass
        // being queried. Resolve it from the scope MethodIdentifier built above; fall back to
        // the queried model when the appearing id is unavailable. `static`/`$this` stay pinned
        // to the queried model inside expandScopeParamSelfReferences() (late static binding).
        $selfClass = $codebase->methods->getAppearingMethodId($scopeMethodId)?->fq_class_name ?? $modelClass;

        return self::$scopeParamsCache[$key] = self::expandScopeParamSelfReferences(
            $codebase,
            $selfClass,
            $modelClass,
            $callerParams,
        );
    }

    /**
     * Resolve the {@see MethodIdentifier} a scope name dispatches to, or null when the model
     * declares no such scope.
     *
     * Mirrors Laravel's Model::callNamedScope precedence: a #[Scope]-attributed method wins
     * over a legacy scopeXxx() twin of the same name. Shared by {@see getScopeParams} (params)
     * and {@see forwardedScopeReturnType} (return type) so both consult one dispatch rule.
     *
     * @param class-string<Model> $modelClass
     */
    private static function resolveScopeMethodId(
        Codebase $codebase,
        string $modelClass,
        string $methodName,
    ): ?MethodIdentifier {
        $methodName = \strtolower($methodName);

        if ($codebase->methodExists($modelClass . '::' . $methodName)
            && self::hasScopeAttribute($codebase, $modelClass, $methodName)
        ) {
            return new MethodIdentifier($modelClass, $methodName);
        }

        if ($codebase->methodExists($modelClass . '::scope' . \ucfirst($methodName))) {
            return new MethodIdentifier($modelClass, 'scope' . $methodName);
        }

        return null;
    }

    /**
     * Apply Laravel's `$scope(...) ?? $this` coalesce (Builder::callScope) to a FORWARDED
     * scope call's return type.
     *
     * A local scope is NOT required to return the builder. callScope evaluates
     * `$result = $scope(...) ?? $this` and returns `$result`, so a scope whose body returns a
     * value (e.g. `->first()`, a count, a collection) propagates that value to the caller,
     * while a null/void/builder-returning scope falls back to `$this`. That `$this` is the
     * builder for the instance and static call forms, and the Relation for a relation chain
     * (Relation::__call returns `$this` only when the forwarded result IS the wrapped query) —
     * the caller passes it as $fallback. This is forwarded-only: a DIRECT call ($this->scope($q))
     * never runs callScope, so its callers decline before reaching here (see isDirectScopeCall).
     *
     * Modeled exactly as PHP's `??`:
     *   - no explicit declared return, or `void`/`never`, or a non-null part that is entirely a
     *     Builder subtype  → $fallback unchanged. This is the overwhelmingly common void/fluent
     *     scope and preserves the pre-#1053 behavior.
     *   - non-null value scope (e.g. `int`)  → the declared type alone; the `?? $this` branch is
     *     dead because the value is never null.
     *   - nullable value scope (e.g. `?Post`)  → `(declared − null) | $fallback`; the null result
     *     is the branch that falls back to $this, so the inferred type never contains null (a
     *     downstream `=== null` check is then correctly redundant, matching the runtime).
     *
     * `self`/`static`/`$this` in the declared return are pinned first — `self` to the scope's
     * composing class, `static`/`$this` to the queried model — so `?self` becomes `?Post` before
     * the null is dropped (mirrors {@see expandScopeParamSelfReferences} for parameters).
     *
     * @see https://github.com/psalm/psalm-plugin-laravel/issues/1053
     * @param class-string<Model> $modelClass
     */
    public static function forwardedScopeReturnType(
        Codebase $codebase,
        string $modelClass,
        string $methodName,
        Union $fallback,
    ): Union {
        $scopeMethodId = self::resolveScopeMethodId($codebase, $modelClass, $methodName);
        if (!$scopeMethodId instanceof \Psalm\Internal\MethodIdentifier) {
            return $fallback;
        }

        $declaringMethodId = $codebase->methods->getDeclaringMethodId($scopeMethodId) ?? $scopeMethodId;

        try {
            $storage = $codebase->methods->getStorage($declaringMethodId);
        } catch (\UnexpectedValueException) {
            return $fallback;
        }

        $declared = $storage->return_type ?? $storage->signature_return_type;

        // No declared return, or `void`/`never`: the scope surfaces nothing, so `?? $this`
        // always falls back to the builder/relation — the long-standing behavior.
        if (!$declared instanceof \Psalm\Type\Union || $declared->isVoid() || $declared->isNever()) {
            return $fallback;
        }

        // Fast path for the common typed-fluent scope (`: Builder`, `: Builder<static>`,
        // `: CustomBuilder`): a non-null builder-only return surfaces no value, so short-circuit
        // to $fallback before the expandUnion + Union-clone work below that would only be
        // discarded at the post-strip builder check. Sound on the RAW type because an atom's
        // builder classification is invariant under expansion — expandUnion only rewrites
        // self/static/$this and class constants (and an alias canonicalizes to the SAME class),
        // never turning a non-builder into a builder or vice versa. It deliberately does NOT fire
        // for `: self`/`: static` (their raw atoms aren't a Builder subtype) nor for a nullable
        // return (the `null` atom fails the all-Builder test) — both still need the expansion
        // below to resolve `?self` → `?Post`.
        if (self::isEntirelyEloquentBuilder($codebase, $declared)) {
            return $fallback;
        }

        // Pin `self` → composing class, `static`/`$this` → queried model, so `?self` resolves to
        // `?Post` before the null is dropped below. `final: true` keeps `static` as the plain
        // model (not `Model&static`): a returned value is checked against the plain class at the
        // call site, exactly as a parameter is (see expandScopeParamSelfReferences).
        $selfClass = $codebase->methods->getAppearingMethodId($scopeMethodId)?->fq_class_name ?? $modelClass;
        $declared = TypeExpander::expandUnion(
            $codebase,
            $declared,
            $selfClass,
            $modelClass,
            null,
            evaluate_class_constants: true,
            evaluate_conditional_types: false,
            final: true,
        );

        $isNullable = $declared->isNullable();

        $nonNull = $declared->getBuilder();
        $nonNull->removeType('null');

        // Declared `null`-only (nothing left after dropping null): always falls back to $this.
        if ($nonNull->getAtomicTypes() === []) {
            return $fallback;
        }

        $nonNullType = $nonNull->freeze();

        // A builder-returning scope (the fluent common case): its declared Builder carries no
        // surfaced value and is normalized to the caller-correct $fallback (Builder<Model> or the
        // Relation), so return $fallback rather than the scope's own (possibly bare) `Builder`.
        if (self::isEntirelyEloquentBuilder($codebase, $nonNullType)) {
            return $fallback;
        }

        // Value-returning scope. A nullable declaration means the null branch falls back to
        // $this; a non-null one can never trigger `?? $this`, so the value stands alone.
        return $isNullable
            ? Type::combineUnionTypes($nonNullType, $fallback, $codebase)
            : $nonNullType;
    }

    /**
     * Whether every atomic in $type is the Eloquent Builder or a subclass of it.
     *
     * A scope declared `: Builder`, `: Builder<static>`, or `: SomeCustomBuilder` is the ordinary
     * fluent scope; its caller normalizes the type to the correct Builder<Model> / Relation
     * fallback rather than surfacing the scope's own builder atom. Query\Builder is intentionally
     * NOT matched: a scope returns the Eloquent builder, never the underlying query builder.
     */
    private static function isEntirelyEloquentBuilder(Codebase $codebase, Union $type): bool
    {
        $builderLower = \strtolower(Builder::class);

        foreach ($type->getAtomicTypes() as $atomic) {
            // TGenericObject extends TNamedObject, so `Builder<static>` is covered here too.
            if (!$atomic instanceof TNamedObject) {
                return false;
            }

            $classLower = \strtolower($atomic->value);
            if ($classLower === $builderLower) {
                continue;
            }

            try {
                if (!$codebase->classExtends($classLower, $builderLower)) {
                    return false;
                }
            } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
                // Unknown/unpopulated class: can't prove it's a builder → treat it as a value.
                return false;
            }
        }

        return true;
    }

    /**
     * Pin `self`/`static`/`$this` (and nested generics like `list<self>`) in scope
     * parameter types to concrete classes, so the same types are checked no matter which
     * class serves the params (a custom builder, the model itself, or a relation chain;
     * all callers share this helper).
     *
     * A scope declared in a trait keeps its `self`/`static` parameter typehints unresolved:
     * Psalm expands them at argument-check time against whatever class the params provider is
     * registered on. For a custom Eloquent builder (Post::query()->scopeMethod($x)) that class
     * is the builder, so `self` wrongly expands to the builder and Psalm emits a false-positive
     * InvalidArgument (issue #1031). Pre-expanding here pins them before the params are handed back.
     *
     * `self` resolves to `$selfClass` — the scope method's *composing* class (the class that
     * uses the trait, or the parent that hosts the scope), NOT the queried subclass. This
     * mirrors PHP: inside a trait method `self` is the class that uses the trait, fixed at
     * composition time, so `Child::query()->traitScope($sibling)` is runtime-valid when both
     * `Child` and the sibling extend the composing parent. Narrowing `self` to the queried
     * subclass (as an earlier revision did) rejected those sibling args with a false positive.
     * For a scope composed directly on the queried model, `$selfClass` *is* that model, so the
     * behavior is unchanged there.
     *
     * `static`/`$this` resolve to `$modelClass` — the queried model (late static binding) — as
     * the plain model class, not the `Model&static` intersection. See the `final: true` argument
     * below for why the intersection would over-reject on this accept-side position.
     *
     * A scope whose `self` is declared *directly* in a class body (not via a trait) — including
     * on an abstract parent — already has `self` resolved to that class by Psalm at scan time.
     * There `$selfClass` equals the declaring class, the param type holds a concrete class with no
     * `self` token left, and the expansion is idempotent (it recomputes the same type).
     *
     * @param string $selfClass `self` → the scope's composing (appearing) class
     * @param class-string<Model> $modelClass `static`/`$this` → the queried model
     * @param list<FunctionLikeParameter> $params
     * @return list<FunctionLikeParameter>
     */
    private static function expandScopeParamSelfReferences(
        Codebase $codebase,
        string $selfClass,
        string $modelClass,
        array $params,
    ): array {
        return \array_map(
            static function (FunctionLikeParameter $param) use ($codebase, $selfClass, $modelClass): FunctionLikeParameter {
                if (!$param->type instanceof \Psalm\Type\Union) {
                    return $param;
                }

                // setType() clones (mutation-free): never mutate the shared method-storage
                // parameter, which would corrupt the model's own scope signature.
                return $param->setType(TypeExpander::expandUnion(
                    $codebase,
                    $param->type,
                    $selfClass, // `self` → composing class (PHP trait `self` semantics)
                    $modelClass, // `static` / `$this` → queried model (late static binding)
                    null, // `parent` — not meaningful for a scope parameter
                    evaluate_class_constants: true,
                    evaluate_conditional_types: false,
                    // `final: true` resolves `static`/`$this` to the plain model class
                    // instead of the late-static-bound `Model&static` form. A scope is a
                    // parameter (accept) position, and Laravel passes the model instance,
                    // so the intersection would wrongly reject a plain model argument
                    // (ArgumentTypeCoercion) — normal contravariance already accepts
                    // subclasses. `self` is unaffected (it never carries `&static`).
                    final: true,
                ));
            },
            $params,
        );
    }

    /**
     * Whether a bare-name scope call dispatches to the model's real method (direct) rather
     * than routing through Laravel's magic __call / __callStatic forwarding.
     *
     * PHP reaches __call / __callStatic ONLY when the named method does not exist under that
     * name OR is inaccessible from the calling scope; an existing, accessible method is
     * invoked directly with its real signature. This classifier mirrors that dispatch rule
     * instead of guessing the call form from argument shapes:
     *
     *   direct  ⇔  the model really declares <methodName> (the BARE name, not scopeXxx)
     *              AND that method is accessible from the caller ($context->self)
     *
     * Why it matters: scope-param stripping ({@see getScopeParams}: declared params minus the
     * leading $query) models the *forwarded* forms, where Laravel injects $query at runtime.
     * A direct call invokes the real method with its full, unstripped signature, so the two
     * {@see ModelMethodHandler} providers (params + return type) must consult this together
     * and decline for direct calls — applying the stripped signature there shifts every
     * argument one position left (false InvalidArgument) and fabricates a Builder<TModel>
     * return over the method's real one (issue #1034).
     *
     * Consequences, each a Laravel runtime truth (verified in Model::__call / Builder::__call
     * and Model::callNamedScope, identical across 12/13):
     *   - Legacy scopeXxx (bare name absent): the bare call has no real target, so Laravel
     *     always forwards → not direct, stripped signature applies.
     *   - public #[Scope]: accessible everywhere → always direct (PHP never reaches __call).
     *   - protected/private #[Scope], caller inside the class hierarchy: accessible → direct
     *     (the issue #1034 sibling form $this->scope($query, ...), now argument-shape
     *     independent: non-variable args, nullable ?Builder, clone $query, variadic scopes
     *     all classify correctly without special-casing).
     *   - protected/private #[Scope], caller outside the hierarchy: inaccessible → forwarded.
     *
     * No caller context (Psalm resolving a method's OWN declaration passes a null context):
     * the call cannot be proven to forward, so a real declared method is treated as direct.
     * Declining there keeps the method's real signature for its body analysis instead of
     * leaking the stripped (query-less) params into the declaration — which otherwise drops
     * $query and raises UndefinedVariable inside a child overriding a parent #[Scope].
     *
     * @param class-string<Model> $modelClass
     */
    public static function isDirectScopeCall(
        Codebase $codebase,
        string $modelClass,
        string $methodName,
        ?Context $context,
    ): bool {
        $methodId = new MethodIdentifier($modelClass, \strtolower($methodName));

        // Bare name is not a real method (legacy scopeXxx, or nothing): Laravel forwards.
        if (!$codebase->methodExists($methodId)) {
            return false;
        }

        // Real method, but no caller scope to test accessibility against (declaration
        // analysis): cannot prove forwarding → treat as direct so the real signature wins.
        if (!$context instanceof Context) {
            return true;
        }

        return self::isMethodAccessibleFrom($codebase, $methodId, $context->self);
    }

    /**
     * Whether $methodId is accessible from $callerClass under PHP's visibility rules.
     *
     * Public is reachable everywhere; from outside any class scope ($callerClass === null,
     * e.g. a free function) only public is reachable. Protected is reachable from anywhere in
     * the declaring class's inheritance chain (the declaring class, its subclasses, and its
     * ancestors). Private is reachable only from the exact declaring class. Mirroring PHP's
     * own dispatch lets an inaccessible method be classified as __call-forwarded.
     *
     * Visibility is read from the *declaring* class's storage, so an inherited or overridden
     * scope is judged where it is actually declared.
     *
     */
    private static function isMethodAccessibleFrom(
        Codebase $codebase,
        MethodIdentifier $methodId,
        ?string $callerClass,
    ): bool {
        $declaringMethodId = $codebase->methods->getDeclaringMethodId($methodId) ?? $methodId;

        try {
            $visibility = $codebase->methods->getStorage($declaringMethodId)->visibility;
        } catch (\UnexpectedValueException) {
            // No storage to inspect — assume accessible (real signature is the safe default).
            return true;
        }

        if ($visibility === ClassLikeAnalyzer::VISIBILITY_PUBLIC) {
            return true;
        }

        if ($callerClass === null) {
            return false;
        }

        $callerClassLower = \strtolower($callerClass);
        $declaringClassLower = \strtolower($declaringMethodId->fq_class_name);

        if ($callerClassLower === $declaringClassLower) {
            return true;
        }

        if ($visibility === ClassLikeAnalyzer::VISIBILITY_PRIVATE) {
            return false;
        }

        // Protected: accessible from anywhere in the declaring class's inheritance chain —
        // a subclass calling an inherited scope, or a parent dispatching to a child override.
        try {
            return $codebase->classExtends($callerClassLower, $declaringClassLower)
                || $codebase->classExtends($declaringClassLower, $callerClassLower);
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            // classExtends (called with from_api: true) throws InvalidArgumentException on
            // missing/aliased storage and UnpopulatedClasslikeException — a sibling
            // LogicException, NOT an InvalidArgumentException — when storage exists but isn't
            // populated yet. Both mean we can't prove the chain → treat as not in it (forwarded).
            return false;
        }
    }

    /**
     * Check if the model has a scope for the given method name.
     *
     * Boolean-equivalent to {@see getScopeParams()} !== null — both detect a #[Scope]-attributed
     * method (visibility-gated) or a legacy scopeXxx() twin — so this delegates instead of
     * duplicating the detection and its cache. getScopeParams returns null cheaply for a
     * non-scope (no param expansion) and memoizes the verdict.
     *
     * Public so ModelMethodHandler can reuse this for method existence checks
     * on static Model calls (e.g., User::active() → scopeActive).
     *
     * @param class-string<\Illuminate\Database\Eloquent\Model> $modelClass
     */
    public static function hasScopeMethod(Codebase $codebase, string $modelClass, string $methodName): bool
    {
        return self::getScopeParams($codebase, $modelClass, $methodName) !== null;
    }

    /**
     * Check if the model has a trait-declared builder method for the given name.
     *
     * Looks for @method static annotations on the model (inherited from traits like
     * SoftDeletes) whose return type is Builder<...>. These methods are registered as
     * Builder macros at runtime and are valid on any Builder instance.
     *
     * Only applies to base-Builder models. Models with custom builders have these
     * methods removed from pseudo_static_methods by ModelRegistrationHandler, so this
     * check correctly returns false for them (they are handled by CustomBuilderMethodHandler).
     *
     * @param class-string<Model> $modelClass
     * @param lowercase-string $methodName
     * @psalm-external-mutation-free
     */
    private static function isTraitBuilderMethod(Codebase $codebase, string $modelClass, string $methodName): bool
    {
        $key = $modelClass . '::' . $methodName;
        if (\array_key_exists($key, self::$traitBuilderCache)) {
            return self::$traitBuilderCache[$key];
        }

        try {
            $storage = $codebase->classlike_storage_provider->get(\strtolower($modelClass));
        } catch (\InvalidArgumentException) {
            return self::$traitBuilderCache[$key] = false;
        }

        $methodStorage = $storage->pseudo_static_methods[$methodName] ?? null;
        if ($methodStorage === null) {
            return self::$traitBuilderCache[$key] = false;
        }

        $returnType = $methodStorage->return_type;
        if ($returnType === null) {
            return self::$traitBuilderCache[$key] = false;
        }

        foreach ($returnType->getAtomicTypes() as $type) {
            if ($type instanceof TGenericObject && \strtolower($type->value) === \strtolower(Builder::class)) {
                return self::$traitBuilderCache[$key] = true;
            }
        }

        return self::$traitBuilderCache[$key] = false;
    }

    /**
     * Extract Builder template type parameters from the LHS type of a method call.
     *
     * Used as a fallback when template params are not passed to the return type provider —
     * specifically when Psalm routes unknown method calls through MissingMethodCallHandler,
     * which invokes return type providers without forwarding the LHS object's type params.
     *
     * A single Builder generic in the LHS is unambiguous. When the LHS is a union of 2+ Builder
     * generics with DIFFERENT template params (e.g. `Builder<A>|Builder<B>` from a ternary),
     * this declines to null: Psalm dispatches the method per-atomic, but the provider event only
     * exposes the whole union, so picking one atomic would silently drop the others and check
     * arguments against the wrong model. Declining yields honest mixed instead of a wrong type.
     *
     * TODO(upstream): MethodReturnTypeProviderEvent / MethodParamsProviderEvent expose no
     * per-atomic context, which forces this union case to mixed. Worth filing a vimeo/psalm
     * feature request so the provider can answer per dispatched atomic.
     *
     * @return non-empty-list<Union>|null
     */
    private static function extractTemplateParamsFromCallStmt(
        MethodCall|StaticCall $stmt,
        StatementsSource $source,
    ): ?array {
        if (!$stmt instanceof MethodCall) {
            return null;
        }

        $lhsType = $source->getNodeTypeProvider()->getType($stmt->var);
        if (!$lhsType instanceof \Psalm\Type\Union) {
            return null;
        }

        $firstParams = null;
        $firstId = null;
        foreach ($lhsType->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TGenericObject || \strtolower($atomic->value) !== \strtolower(Builder::class)) {
                continue;
            }

            $id = self::templateParamsId($atomic->type_params);
            if ($firstParams === null) {
                $firstParams = $atomic->type_params;
                $firstId = $id;
            } elseif ($id !== $firstId) {
                // 2+ distinct Builder generics in the union → can't resolve one honestly.
                return null;
            }
        }

        return $firstParams;
    }

    /**
     * Stable identity for a Builder atomic's template params, so identical generics
     * (Builder<A>|Builder<A>) collapse while distinct ones (Builder<A>|Builder<B>) compare unequal.
     *
     * @param list<Union> $params
     * @psalm-mutation-free
     */
    private static function templateParamsId(array $params): string
    {
        return \implode(',', \array_map(static fn(Union $param): string => $param->getId(), $params));
    }

    /**
     * Check for #[Scope] attribute using Psalm's method storage rather than runtime Reflection.
     *
     * Psalm stores attribute metadata on the *declaring* class's MethodStorage — the trait or
     * parent that defines the method — not on the using/inheriting class. Resolving through
     * getDeclaringMethodId first ensures that a #[Scope] attribute on a trait-declared method
     * is found, even though the composing model's storage entry does not duplicate the attributes.
     *
     * @param class-string<Model> $modelClass
     * @psalm-mutation-free
     */
    private static function hasScopeAttribute(Codebase $codebase, string $modelClass, string $methodName): bool
    {
        $methodId = new MethodIdentifier($modelClass, \strtolower($methodName));
        $declaringMethodId = $codebase->methods->getDeclaringMethodId($methodId) ?? $methodId;

        try {
            $methodStorage = $codebase->methods->getStorage($declaringMethodId);
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            return false;
        }

        // Shared with SuppressHandler (#874) and PublicScopeAccessorVisibilityHandler (#695): the
        // attribute check plus the private-#[Scope] gate live in EloquentModelMethods::hasScopeAttribute.
        return EloquentModelMethods::hasScopeAttribute($methodStorage);
    }
}
