<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\Type\TypeExpander;
use Psalm\LaravelPlugin\Util\ModelPropertyResolver;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodParamsProviderInterface;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\StatementsSource;
use Psalm\Storage\FunctionLikeParameter;
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
    /** @var array<string, bool> */
    private static array $scopeCache = [];

    /**
     * Cache for isTraitBuilderMethod results.
     *
     * Separate from $scopeCache to keep concerns distinct.
     *
     * @var array<string, bool>
     */
    private static array $traitBuilderCache = [];

    /**
     * Scope params hand-off: lowercase scope name -> params (minus the leading $query).
     *
     * Populated by the return type provider when it resolves an instance scope call
     * (Customer::query()->active()); consumed by {@see getMethodParams} when Psalm
     * immediately follows up with checkMethodArgs for the same call. Keyed by method
     * name only: the producer always runs right before the consumer within one
     * analysis thread, so the latest entry matches the call being checked even when
     * two models declare a scope with the same name. Entries persist across files,
     * so the consumer rejects names that are real Builder methods (see
     * {@see isRealBuilderMethod}) to avoid shadowing genuine calls analyzed later.
     *
     * @var array<lowercase-string, list<FunctionLikeParameter>>
     */
    private static array $pendingScopeParams = [];

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

        // A non-null result implies the model declares the scope (detection is strict,
        // see getScopeParams). On failure to resolve we decline entirely — falling back
        // to mixed is honest, while returning a type with a fabricated zero-param
        // signature would emit TooManyArguments on every scope call with args.
        $scopeParams = self::getScopeParams($codebase, $modelClass, $methodName);
        if ($scopeParams !== null) {
            // Hand the scope's params (minus the leading $query) to the params
            // provider: Psalm follows this return type with checkMethodArgs for
            // Builder::<scope>, which has no real method storage and would otherwise
            // throw UnexpectedValueException in Codebase\Methods::getMethodParams.
            self::$pendingScopeParams[$methodName] = $scopeParams;

            return $builderReturn;
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
     * Covers instance scope calls via the {@see $pendingScopeParams} hand-off populated
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

        $scopeParams = self::$pendingScopeParams[$methodName] ?? null;
        if ($scopeParams !== null && !self::isRealBuilderMethod($event, $methodName)) {
            return $scopeParams;
        }

        return self::$baseBuilderTraitMethods[$methodName] ?? null;
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
        $key = $modelClass . '::' . $methodName;
        if (\array_key_exists($key, self::$scopeParamsCache)) {
            return self::$scopeParamsCache[$key];
        }

        if ($codebase->methodExists($modelClass . '::' . $methodName)
            && self::hasScopeAttribute($codebase, $modelClass, $methodName)
        ) {
            /** @var lowercase-string $methodName */
            $scopeMethodId = new MethodIdentifier($modelClass, $methodName);
        } elseif ($codebase->methodExists($modelClass . '::scope' . \ucfirst($methodName))) {
            /** @var lowercase-string $legacyScopeLower */
            $legacyScopeLower = 'scope' . $methodName;
            $scopeMethodId = new MethodIdentifier($modelClass, $legacyScopeLower);
        } else {
            return self::$scopeParamsCache[$key] = null;
        }

        // Methods::getMethodParams resolves the declaring class internally, so scopes
        // declared on abstract parent models or in traits keep their signatures. Drop the
        // leading $query param; the caller passes everything after it.
        $callerParams = \array_slice($codebase->methods->getMethodParams($scopeMethodId), 1);

        // expandScopeParamSelfReferences() pins self/static to the model (see its docblock).
        // Memoized, so it runs once per model+method.
        return self::$scopeParamsCache[$key] = self::expandScopeParamSelfReferences($codebase, $modelClass, $callerParams);
    }

    /**
     * Pin `self`/`static`/`$this` (and nested generics like `list<self>`) in scope
     * parameter types to the model class.
     *
     * A scope declared in a trait keeps its `self`/`static` parameter typehints
     * unresolved: Psalm expands them at argument-check time against whatever class the
     * params provider is registered on. For a custom Eloquent builder
     * (Post::query()->scopeMethod($x)) that class is the builder, so `self` wrongly
     * expands to the builder and Psalm emits a false-positive InvalidArgument (issue
     * #1031). Pre-expanding here pins `self`/`static`/`$this` to the model before the
     * params are handed back, so the same concrete type is checked regardless of which
     * class serves the params (a custom builder, the model itself, or a relation chain;
     * all callers share this helper).
     *
     * `static`/`$this` are resolved to the plain model class (via TypeExpander's
     * `final: true`), NOT the late-static-bound `Model&static` form. A scope parameter is
     * an accept position and Laravel passes the model instance, so the `&static`
     * intersection would wrongly reject a plain model argument (ArgumentTypeCoercion);
     * a plain model param already accepts subclass instances by ordinary subtyping.
     * Scopes declared directly on the model already resolve `self` to the model at scan
     * time, so this is a no-op for them.
     *
     * Caveat: for a trait-hosted scope, `self`/`static` pin to the model being queried,
     * not necessarily the class that declares the trait. Querying a subclass narrows the
     * param to that subclass — strictly more precise than the pre-fix builder type, and
     * correct for the common single-using-class case.
     *
     * @param class-string<Model> $modelClass
     * @param list<FunctionLikeParameter> $params
     * @return list<FunctionLikeParameter>
     */
    private static function expandScopeParamSelfReferences(
        Codebase $codebase,
        string $modelClass,
        array $params,
    ): array {
        return \array_map(
            static function (FunctionLikeParameter $param) use ($codebase, $modelClass): FunctionLikeParameter {
                if (!$param->type instanceof \Psalm\Type\Union) {
                    return $param;
                }

                // setType() clones (mutation-free): never mutate the shared method-storage
                // parameter, which would corrupt the model's own scope signature.
                return $param->setType(TypeExpander::expandUnion(
                    $codebase,
                    $param->type,
                    $modelClass, // `self`
                    $modelClass, // `static` / `$this`
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
     * @psalm-mutation-free
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
     * Whether the Builder (or the mixed-in Query\Builder) declares the method for real.
     *
     * Guards the scope hand-off in {@see getMethodParams}: $pendingScopeParams entries
     * persist across files, so a model's scopeLatest() must not shadow a genuine
     * Builder::latest() call on an unrelated model analyzed later — real methods keep
     * their own storage-backed params.
     */
    private static function isRealBuilderMethod(MethodParamsProviderEvent $event, string $methodName): bool
    {
        $source = $event->getStatementsSource();
        if (!$source instanceof StatementsSource) {
            return false;
        }

        $codebase = $source->getCodebase();

        return $codebase->methodExists(Builder::class . '::' . $methodName)
            || $codebase->methodExists(QueryBuilder::class . '::' . $methodName);
    }

    /**
     * Check if the model has a scope for the given method name.
     *
     * Public so ModelMethodHandler can reuse this for method existence checks
     * on static Model calls (e.g., User::active() → scopeActive).
     *
     * @param class-string<\Illuminate\Database\Eloquent\Model> $modelClass
     */
    public static function hasScopeMethod(Codebase $codebase, string $modelClass, string $methodName): bool
    {
        $key = $modelClass . '::' . $methodName;

        if (\array_key_exists($key, self::$scopeCache)) {
            return self::$scopeCache[$key];
        }

        // Check legacy scope prefix: scopeActive → active
        $legacyScopeMethod = $modelClass . '::scope' . \ucfirst($methodName);
        if ($codebase->methodExists($legacyScopeMethod)) {
            self::$scopeCache[$key] = true;
            return true;
        }

        // Check #[Scope] attribute via Psalm's storage instead of runtime Reflection.
        // This avoids loading the model class into PHP's runtime and constructing
        // ReflectionMethod objects for every non-scope method on the model.
        $directMethod = $modelClass . '::' . $methodName;
        if ($codebase->methodExists($directMethod) && self::hasScopeAttribute($codebase, $modelClass, $methodName)) {
            self::$scopeCache[$key] = true;
            return true;
        }

        self::$scopeCache[$key] = false;
        return false;
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

        foreach ($lhsType->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TGenericObject && \strtolower($atomic->value) === \strtolower(Builder::class)) {
                return $atomic->type_params;
            }
        }

        return null;
    }

    /**
     * Check for #[Scope] attribute using Psalm's method storage rather than runtime Reflection.
     *
     * @param class-string<Model> $modelClass
     * @psalm-mutation-free
     */
    private static function hasScopeAttribute(Codebase $codebase, string $modelClass, string $methodName): bool
    {
        try {
            $methodStorage = $codebase->methods->getStorage(
                new MethodIdentifier($modelClass, \strtolower($methodName)),
            );
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            return false;
        }

        // A private #[Scope] is never a usable scope on any supported Laravel (12-13). Laravel
        // 13.8+ rejects it outright in Model::isScopeMethodWithAttribute (`! isPrivate() && ...`);
        // on 12.4–13.7 it is broken anyway — callNamedScope dispatches the scope from the base
        // Model's $this, where the subclass's private method is unreachable, so it routes back
        // through __call and recurses. Either way it can't dispatch, so a legacy scopeXxx twin
        // (resolved separately) wins, or it is nothing.
        if ($methodStorage->visibility === ClassLikeAnalyzer::VISIBILITY_PRIVATE) {
            return false;
        }

        foreach ($methodStorage->attributes as $attribute) {
            if ($attribute->fq_class_name === Scope::class) {
                return true;
            }
        }

        return false;
    }
}
