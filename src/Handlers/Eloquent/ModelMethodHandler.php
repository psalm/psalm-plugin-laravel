<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\Type\TypeExpander;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\DynamicWhereResolver;
use Psalm\LaravelPlugin\Internal\ProxyMethodReturnTypeProvider;
use Psalm\Plugin\EventHandler\Event\MethodExistenceProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodVisibilityProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\StatementsSource;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type;
use Psalm\Type\Union;

/**
 * Handles static method calls on Eloquent Models that are forwarded to Builder via __callStatic.
 *
 * Responsibilities:
 * 1. Method existence — confirms magic methods exist (suppresses UndefinedMagicMethod)
 * 2. Method visibility — confirms magic methods are public
 * 3. Method params — provides parameter definitions for argument checking
 * 4. Return types — proxies calls to Builder<TModel> for type inference
 *
 * Existence, visibility, params, and return type providers for concrete Model subclasses are
 * registered dynamically per-model by {@see ModelRegistrationHandler} because Psalm's
 * provider lookup requires exact class name matching — a handler registered for Model::class
 * is not consulted for concrete subclasses like App\Models\User.
 *
 * The getClassLikeNames() registration for Model::class handles `Model::query()` only when
 * a custom Eloquent builder is registered for the called model. For plain models (base
 * `Builder`), `query()` is intentionally deferred to the stub at
 * `stubs/common/Database/Eloquent/Model.phpstub` whose `@return Builder<static>` is the only
 * way to preserve the `&static` intersection through Psalm's template binding (see #799).
 * `__callStatic` is similarly proxied for methods resolvable through the single-hop mixin
 * chain.
 *
 * Builder-instance handlers (trait methods and scopes on custom builders) are in
 * {@see CustomBuilderMethodHandler}.
 */
final class ModelMethodHandler implements MethodReturnTypeProviderInterface
{
    /**
     * Cache for isUnresolvedBuilderMethod results.
     *
     * This method is called up to 4 times per static method call (existence, visibility,
     * params, return type), so caching avoids redundant methodExists lookups.
     *
     * The dynamic-where branch of isUnresolvedBuilderMethod consults
     * {@see DynamicWhereResolver::isEnabled}, so a stale entry produced under a
     * previous "enabled" configuration could leak into a subsequent "disabled"
     * bootstrap in the same process. Plugin re-bootstrap clears this cache via
     * {@see init} to keep the cached verdict consistent with the active config.
     *
     * @var array<string, bool>
     */
    private static array $unresolvedCache = [];

    /**
     * Maps model FQCN → custom Eloquent builder FQCN.
     *
     * Populated by {@see ModelRegistrationHandler} when a model declares a dedicated builder
     * via #[UseEloquentBuilder] attribute, newEloquentBuilder() override, or $builder property.
     * Used to return the correct builder type from query(), __callStatic, and scope methods.
     *
     * @var array<class-string<Model>, class-string<Builder>>
     */
    private static array $customBuilderMap = [];

    /**
     * Memoized result of {@see expandedGlobalScopeParams} keyed by model FQCN.
     * Cleared by {@see init} on plugin re-bootstrap.
     *
     * @var array<string, list<FunctionLikeParameter>|null>
     */
    private static array $globalScopeParamsCache = [];

    /**
     * Reset per-process caches. Called once per Plugin::__construct so a re-bootstrap
     * doesn't carry stale verdicts across analysis runs. In particular, the
     * dynamic-where branch of {@see isUnresolvedBuilderMethod} depends on the
     * runtime-mutable {@see DynamicWhereResolver::isEnabled} flag; clearing the cache
     * here ensures a `resolveDynamicWhereClauses` flip is honoured on the next run.
     *
     * @psalm-external-mutation-free
     */
    public static function init(): void
    {
        self::$unresolvedCache = [];
        self::$customBuilderMap = [];
        self::$globalScopeParamsCache = [];
    }

    /**
     * Register a custom Eloquent builder class for a model.
     *
     * @param class-string<Model> $modelClass
     * @param class-string<Builder> $builderClass
     * @psalm-external-mutation-free
     */
    public static function registerCustomBuilder(string $modelClass, string $builderClass): void
    {
        self::$customBuilderMap[$modelClass] = $builderClass;
        CustomBuilderMethodHandler::registerBuilderToModelMapping($modelClass, $builderClass);
    }

    /**
     * Get the builder class for a model — custom builder if registered, base Builder otherwise.
     *
     * @psalm-external-mutation-free
     */
    private static function getBuilderClassForModel(string $modelClass): string
    {
        return self::$customBuilderMap[$modelClass] ?? Builder::class;
    }

    /**
     * Build the Psalm type for the builder used by a model.
     *
     * @internal Used by magic forwarding handlers that intercept Model's @mixin path
     * @psalm-external-mutation-free
     */
    public static function resolvedBuilderTypeFor(string $modelClass, Codebase $codebase): Type\Atomic\TNamedObject
    {
        return self::builderType(self::getBuilderClassForModel($modelClass), $modelClass, $codebase);
    }

    /**
     * Build the Psalm type for Builder<Model> (or CustomBuilder<Model>).
     *
     * If the custom builder has template parameters, returns TGenericObject (e.g. PostBuilder<Post>).
     * If the builder has no template params (e.g. `final class MemberBuilder extends Builder<Member>`),
     * returns a plain TNamedObject (just MemberBuilder) to avoid "too many template params" errors.
     *
     * @internal Used by {@see CustomBuilderMethodHandler} for builder-instance return types
     * @psalm-mutation-free
     */
    public static function builderType(
        string $builderClass,
        string $modelClass,
        Codebase $codebase,
    ): Type\Atomic\TNamedObject {
        // Non-custom builders (base Builder) always have the TModel template param.
        if ($builderClass === Builder::class) {
            return new Type\Atomic\TGenericObject($builderClass, [
                new Union([new Type\Atomic\TNamedObject($modelClass)]),
            ]);
        }

        // Custom builders: check if they declare their own template params.
        try {
            $storage = $codebase->classlike_storage_provider->get(\strtolower($builderClass));
        } catch (\InvalidArgumentException) {
            return new Type\Atomic\TNamedObject($builderClass);
        }

        if ($storage->template_types !== null && $storage->template_types !== []) {
            return new Type\Atomic\TGenericObject($builderClass, [
                new Union([new Type\Atomic\TNamedObject($modelClass)]),
            ]);
        }

        return new Type\Atomic\TNamedObject($builderClass);
    }

    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [Model::class];
    }

    /**
     * Confirm that a static magic method exists on a Model subclass.
     *
     * Only confirms methods NOT already resolvable through Psalm's @mixin chain.
     * Methods on Eloquent\Builder (like where(), get()) are found by Psalm via
     * Model's @mixin Builder<static> automatically. Confirming them here would
     * set $fake_method_exists=true in the analyzer, bypassing mixin resolution
     * for instance calls and losing type information.
     *
     * We only handle:
     * 1. Query\Builder methods not explicitly on Eloquent\Builder (e.g., whereIn, orderBy)
     *    These can't be reached through the double mixin: Model → Builder → Query\Builder.
     * 2. Scope methods (legacy scopeX or #[Scope] attribute)
     *
     * Registered as a closure per concrete Model class by {@see ModelRegistrationHandler}.
     */
    public static function doesMethodExist(MethodExistenceProviderEvent $event): ?bool
    {
        $source = $event->getSource();

        if (!$source instanceof \Psalm\StatementsSource) {
            return null;
        }

        return self::isUnresolvedBuilderMethod(
            $source->getCodebase(),
            $event->getFqClasslikeName(),
            $event->getMethodNameLowercase(),
        ) ? true : null;
    }

    /**
     * Magic methods forwarded via __callStatic are effectively public.
     *
     * Registered defensively per concrete Model class by {@see ModelRegistrationHandler}
     * in case Psalm's visibility check is reached for fake-method-exists paths.
     */
    public static function isMethodVisible(MethodVisibilityProviderEvent $event): ?bool
    {
        return self::isUnresolvedBuilderMethod(
            $event->getSource()->getCodebase(),
            $event->getFqClasslikeName(),
            $event->getMethodNameLowercase(),
        ) ? true : null;
    }

    /**
     * Provide method parameter definitions for methods confirmed by doesMethodExist.
     *
     * Psalm needs to know the parameter types for argument checking (checkMethodArgs).
     * For Query\Builder methods, we delegate to the actual Query\Builder params.
     * For scope methods, we use the scope's params minus the first $query parameter.
     *
     * Registered as a closure per concrete Model class by {@see ModelRegistrationHandler}.
     *
     * @return list<FunctionLikeParameter>|null
     */
    public static function getMethodParams(MethodParamsProviderEvent $event): ?array
    {
        $source = $event->getStatementsSource();

        if (!$source instanceof StatementsSource) {
            return null;
        }

        $codebase = $source->getCodebase();
        $modelClass = $event->getFqClasslikeName();
        $methodName = $event->getMethodNameLowercase();

        // addGlobalScope: re-expand formal params with final: true so Builder<static> resolves
        // to Builder<ModelClass> without the &static intersection Psalm adds for non-final classes.
        // The intersection causes false InvalidArgument when a closure uses a bare `Builder $query`
        // hint, because template inference gives Builder<static> on the provided side while the
        // expected side carries Builder<User&static> — unification fails (issue #1038).
        if ($methodName === 'addglobalscope') {
            return self::expandedGlobalScopeParams($codebase, $modelClass);
        }

        if (!self::isUnresolvedBuilderMethod($codebase, $modelClass, $methodName)) {
            return null;
        }

        // Custom builder method — use its actual params (e.g., PostBuilder::wherePublished)
        $builderClass = self::getBuilderClassForModel($modelClass);
        if ($builderClass !== Builder::class) {
            /** @var lowercase-string $methodName */
            $customBuilderMethodId = new MethodIdentifier($builderClass, $methodName);
            if ($codebase->methodExists($customBuilderMethodId)) {
                return self::getParamsWithVariadicFlag($codebase, $customBuilderMethodId);
            }
        }

        // Trait-declared builder method — use stored params from the original @method annotation.
        $traitParams = CustomBuilderMethodHandler::getTraitMethodParams($modelClass, $methodName);
        if ($traitParams !== null) {
            return $traitParams;
        }

        // Scope method — checked BEFORE Query\Builder forwarded methods because Laravel's
        // Builder::__call tests hasNamedScope() before forwardCallTo($this->query, ...).
        // A model scope whose name matches a Query\Builder-only method (e.g. scopeOrderBy)
        // shadows that method at runtime, so the scope's params must win here too.
        // isUnresolvedBuilderMethod() has already excluded real Eloquent\Builder methods
        // (those are invoked directly by PHP, not via __call), so every scope reaching
        // this point is a legitimate shadow of a Query\Builder-forwarded name.
        /** @var class-string<Model> $modelClass */
        $scopeParams = BuilderScopeHandler::getScopeParams($codebase, $modelClass, $methodName);
        if ($scopeParams !== null) {
            // A direct call ($this->otherScope($query, ...) inside the model, or any call to an
            // accessible real scope method) invokes the real method, so its full declared
            // signature — including the leading $query — applies. Only the magic-forwarded forms
            // (Model::scope() via __callStatic, $builder->scope()) inject $query and need the
            // stripped params. The classifier decides from PHP dispatch semantics, not argument
            // shapes, and declines for direct calls so Psalm checks the real signature instead of
            // shifting every argument left by one. A null context (Psalm resolving this method's
            // own declaration) also declines, keeping $query in scope for an overriding child.
            // See issue #1034.
            if (BuilderScopeHandler::isDirectScopeCall($codebase, $modelClass, $methodName, $event->getContext())) {
                return null;
            }

            return $scopeParams;
        }

        // Query\Builder method — use its actual params
        /** @var lowercase-string $methodName */
        $queryBuilderMethodId = new MethodIdentifier(QueryBuilder::class, $methodName);
        if ($codebase->methodExists($queryBuilderMethodId)) {
            return self::getParamsWithVariadicFlag($codebase, $queryBuilderMethodId);
        }

        // Dynamic where{Column}: gated on resolveDynamicWhereClauses (issue #1000).
        // Mirrors the relation-chain fallback: typed single param when the return-type
        // provider resolved a scalar column (issue #928 hand-off), variadic mixed
        // otherwise so Psalm doesn't raise TooManyArguments on multi-segment forms
        // like whereFirstNameAndLastName($a, $b).
        if (DynamicWhereResolver::isEnabled() && DynamicWhereResolver::isDynamicWhereMethod($methodName)) {
            return (
                DynamicWhereResolver::consumeTypedParams(
                    $methodName,
                    $event->getCallArgs(),
                ) ?? DynamicWhereResolver::variadicMixedParams()
            );
        }

        return null;
    }

    /**
     * Provide return types for methods confirmed by doesMethodExist.
     *
     * When the existence provider confirms a method (e.g., whereIn, active), Psalm calls
     * the return type provider with the method name directly (not __callstatic). This handler
     * proxies the call to Builder<ModelClass> to resolve the return type.
     *
     * Registered as a closure per concrete Model class by {@see ModelRegistrationHandler}.
     */
    public static function getReturnTypeForForwardedMethod(MethodReturnTypeProviderEvent $event): ?Union
    {
        $source = $event->getSource();

        if (!$source instanceof StatementsAnalyzer) {
            return null;
        }

        $codebase = $source->getCodebase();
        $modelClass = $event->getFqClasslikeName();
        $methodName = $event->getMethodNameLowercase();

        // Only handle methods confirmed by doesMethodExist — don't interfere with
        // methods already resolved through the @mixin chain (where, get, first, etc.)
        if (!self::isUnresolvedBuilderMethod($codebase, $modelClass, $methodName)) {
            return null;
        }

        $calledClass = $event->getCalledFqClasslikeName() ?? $modelClass;

        // Use $modelClass for builder lookup — matches the registration key in $customBuilderMap.
        // $calledClass is used only for the template parameter (TNamedObject) in the return type.
        $builderClass = self::getBuilderClassForModel($modelClass);

        // Scope methods: return Builder<Model> directly.
        // Using executeFakeCall for scopes doesn't work reliably because the scope
        // is resolved via Builder's __call magic which may fail in a fake call context.
        // A non-null getScopeParams() result implies the method is a real scope — no
        // separate hasScopeMethod() guard needed (mirrors the params provider above).
        /** @var class-string<Model> $modelClass */
        $scopeParams = BuilderScopeHandler::getScopeParams($codebase, $modelClass, $methodName);
        if ($scopeParams !== null) {
            // Direct calls to the underlying method ($this->someScope($query, ...), or any call
            // to an accessible real scope method) return whatever the method declares (often
            // void), not Builder<Model> — decline and let Psalm use the real declared return
            // type. The classifier shares the params provider's dispatch-truth verdict so the
            // two decline together (issue #1034).
            if (BuilderScopeHandler::isDirectScopeCall($codebase, $modelClass, $methodName, $event->getContext())) {
                return null;
            }

            // A value-returning scope surfaces its declared return via Laravel's `?? $this`
            // coalesce; a plain void/fluent scope keeps the builder type (issue #1053).
            $scopeFallback = new Union([self::builderType($builderClass, $calledClass, $codebase)]);

            return BuilderScopeHandler::forwardedScopeReturnType($codebase, $modelClass, $methodName, $scopeFallback);
        }

        // Trait-declared builder methods (e.g., SoftDeletes::withTrashed): return custom builder type.
        if (CustomBuilderMethodHandler::hasTraitMethod($modelClass, $methodName)) {
            return new Union([self::builderType($builderClass, $calledClass, $codebase)]);
        }

        // Dynamic where{Column}: gated on resolveDynamicWhereClauses (issue #1000).
        // doesMethodExist has already confirmed via DynamicWhereResolver::methodMatchesColumns
        // that the lowercase suffix matches columns. The gate excludes both Query\Builder
        // methods (handled by the fake-call branch below) AND methods declared on the
        // registered builder class itself — for a custom builder that defines `whereFoo()`
        // directly, the fake-call path resolves it with the method's actual return type
        // instead of unconditionally substituting Builder<TModel>.
        //
        // Strict camel-cased validation (`resolveColumnType`) decides whether to queue the
        // typed-param hand-off for #928 and gates the final return: a `false` result means
        // the lowercase backtracker over-accepted (e.g. `wherefoobar` with @property `foo`
        // and `bar` only matches via lowercase, not the camel-cased And/Or split that
        // Laravel's runtime requires). Returning null in that case avoids claiming
        // Builder<TModel> for a call Laravel would parse differently — existence remains
        // confirmed so Psalm doesn't raise UndefinedMagicMethod, but the return type stays
        // Psalm's default rather than a fabricated builder.
        if (
            DynamicWhereResolver::isEnabled()
            && DynamicWhereResolver::isDynamicWhereMethod($methodName)
            && !$codebase->methodExists(new MethodIdentifier(QueryBuilder::class, $methodName))
            && !$codebase->methodExists(new MethodIdentifier($builderClass, $methodName))
        ) {
            $stmt = $event->getStmt();
            $originalMethodName = DynamicWhereResolver::originalMethodName($stmt, $methodName);
            $columnType = DynamicWhereResolver::resolveColumnType($codebase, $modelClass, $originalMethodName);

            if ($columnType === false) {
                return null;
            }

            // Queue typed param hand-off (issue #928) only when the producer/consumer
            // contract holds: single-segment scalar column + exactly one argument. Mirrors
            // {@see \Psalm\LaravelPlugin\Handlers\Magic\MethodForwardingHandler::resolveDynamicWhereOnRelation}.
            if ($columnType instanceof Union) {
                $args = $stmt->getArgs();

                if (\count($args) === 1) {
                    DynamicWhereResolver::storePendingColumnType($methodName, $args[0], $columnType);
                }
            }

            return new Union([self::builderType($builderClass, $calledClass, $codebase)]);
        }

        // Query\Builder methods: proxy the call through Builder<Model> to resolve
        // the return type with proper template type preservation.
        $fake_method_call = new MethodCall(
            // Variable name must match what executeFakeCall sets in context: $fakeProxyObject
            new Variable('fakeProxyObject'),
            $methodName,
            $event->getCallArgs(),
        );

        $fakeProxy = self::builderType($builderClass, $calledClass, $codebase);

        return ProxyMethodReturnTypeProvider::executeFakeCall($source, $fake_method_call, $event->getContext(), $fakeProxy);
    }

    /**
     * Check if a method on a Model needs explicit existence confirmation.
     *
     * Returns true only for methods that Psalm can't resolve via its normal
     * @mixin chain — either Query\Builder methods unreachable through the double
     * mixin hop, or scope methods defined on the model.
     */
    private static function isUnresolvedBuilderMethod(Codebase $codebase, string $modelClass, string $methodName): bool
    {
        $key = $modelClass . '::' . $methodName;

        if (\array_key_exists($key, self::$unresolvedCache)) {
            return self::$unresolvedCache[$key];
        }

        /** @var lowercase-string $methodName */

        // Methods defined directly on the Model class (e.g., newQuery, newModelQuery)
        // should be resolved by Psalm normally using the stub/source return types.
        // Don't intercept them — otherwise Query\Builder methods with the same name
        // (like Query\Builder::newQuery()) would shadow the Model's own definition.
        if ($codebase->methodExists(new MethodIdentifier(Model::class, $methodName))) {
            return self::$unresolvedCache[$key] = false;
        }

        // Methods on Eloquent\Builder (e.g., where, get, first) are resolved by Psalm
        // via Model's @mixin Builder<static>. Don't interfere — let the mixin handle it.
        if ($codebase->methodExists(new MethodIdentifier(Builder::class, $methodName))) {
            return self::$unresolvedCache[$key] = false;
        }

        // Methods on Query\Builder that are NOT on Eloquent\Builder (e.g., whereIn,
        // orderBy). At runtime these are forwarded via Builder::__call → forwardCallTo.
        // Psalm's single-level mixin resolution can't reach them through the double hop:
        // Model → Builder → Query\Builder. Confirm existence so Psalm doesn't emit
        // UndefinedMagicMethod.
        if ($codebase->methodExists(new MethodIdentifier(QueryBuilder::class, $methodName))) {
            return self::$unresolvedCache[$key] = true;
        }

        // Methods on a custom builder class (e.g., PostBuilder::wherePublished).
        // These are declared directly on the custom builder and forwarded via __callStatic.
        $builderClass = self::getBuilderClassForModel($modelClass);
        if (
            $builderClass !== Builder::class
            && $codebase->methodExists(new MethodIdentifier($builderClass, $methodName))
        ) {
            return self::$unresolvedCache[$key] = true;
        }

        // Trait-declared builder methods (e.g., SoftDeletes::withTrashed, onlyTrashed).
        // These are @method static on model traits that return Builder<static>. For models
        // with custom builders, removed from pseudo_static_methods so we control the return type.
        if (CustomBuilderMethodHandler::hasTraitMethod($modelClass, $methodName)) {
            return self::$unresolvedCache[$key] = true;
        }

        // Scope methods (e.g., scopeActive → active, #[Scope] verified → verified).
        // These are defined on the model and forwarded via __callStatic → Builder.
        /** @var class-string<Model> $modelClass */
        if (BuilderScopeHandler::hasScopeMethod($codebase, $modelClass, $methodName)) {
            return self::$unresolvedCache[$key] = true;
        }

        // Dynamic where{Column} methods (e.g., whereUuid, whereFirstNameAndLastName).
        // Laravel's `Builder::dynamicWhere` resolves these at runtime when the suffix
        // segments match column names. When `<resolveDynamicWhereClauses value="true" />`
        // is set (default), confirm existence so a direct Model::whereCol(...) call
        // doesn't raise UndefinedMagicMethod (issue #1000).
        //
        // The match is lowercase-only because the existence hook receives no AST node
        // (so we can't reconstruct the original camel-cased suffix here). The return-
        // type and params providers still run the strict camel-cased validation via
        // DynamicWhereResolver::resolveColumnType when they fire on the confirmed call.
        if (
            DynamicWhereResolver::isEnabled()
            && DynamicWhereResolver::methodMatchesColumns($codebase, $modelClass, $methodName)
        ) {
            return self::$unresolvedCache[$key] = true;
        }

        return self::$unresolvedCache[$key] = false;
    }

    /**
     * Whether a bare method name on $modelClass forwards to its query builder (default or custom):
     * an Eloquent\Builder method reachable via the @mixin (`where`, `find`, `first`, `get`), a
     * Query\Builder method (`orderBy`, `whereIn`), a custom builder or trait builder method, or a
     * resolvable dynamic `where{Column}()` clause. Real methods declared on the model itself are
     * excluded. Scopes may also report true here (the shared {@see isUnresolvedBuilderMethod}
     * logic includes them), so a caller that must treat scopes specially should consult
     * {@see BuilderScopeHandler::hasScopeMethod()} first.
     *
     * @internal Used by {@see \Psalm\LaravelPlugin\Handlers\Rules\ImplicitQueryBuilderCallHandler}
     * to decide whether a direct model call is magic forwarding without duplicating the resolution.
     *
     * @param class-string<Model> $modelClass
     * @param lowercase-string $methodNameLower
     */
    public static function forwardsToQueryBuilder(Codebase $codebase, string $modelClass, string $methodNameLower): bool
    {
        // Eloquent\Builder methods (where/find/create/first/get) resolve via Model's @mixin, so
        // isUnresolvedBuilderMethod reports them as already-resolved (false); check them here.
        if ($codebase->methodExists(new MethodIdentifier(Builder::class, $methodNameLower))) {
            return true;
        }

        return self::isUnresolvedBuilderMethod($codebase, $modelClass, $methodNameLower);
    }

    /** @inheritDoc */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Type\Union
    {
        $source = $event->getSource();

        if (!$source instanceof StatementsAnalyzer) {
            return null;
        }

        $codebase = $source->getCodebase();
        $called_fq_classlike_name = $event->getCalledFqClasslikeName();

        if (!\is_string($called_fq_classlike_name)) {
            return null;
        }

        // Model::query()
        if ($event->getMethodNameLowercase() === 'query') {
            $builderClass = self::getBuilderClassForModel($called_fq_classlike_name);

            // For models without a custom builder, defer to the stub's
            // `@return Builder<static>` annotation. Returning `Builder<ConcreteModel>`
            // here flattens `static`, which then breaks return-type inference inside
            // methods declared `: static` (e.g. `static::query()->firstOrCreate(...)`)
            // because the `&static` intersection is lost across the template binding.
            // See https://github.com/psalm/psalm-plugin-laravel/issues/799.
            if ($builderClass === Builder::class) {
                return null;
            }

            return new Union([self::builderType($builderClass, $called_fq_classlike_name, $codebase)]);
        }

        // proxy to builder object
        if ($event->getMethodNameLowercase() === '__callstatic') {
            $called_method_name_lowercase = $event->getCalledMethodNameLowercase();

            if ($called_method_name_lowercase === null) {
                return null;
            }

            $methodId = new MethodIdentifier($called_fq_classlike_name, $called_method_name_lowercase);
            $builderClass = self::getBuilderClassForModel($called_fq_classlike_name);

            $fake_method_call = new MethodCall(new Variable('builder'), $methodId->method_name, $event->getCallArgs());

            $fakeProxy = self::builderType($builderClass, $called_fq_classlike_name, $codebase);

            return ProxyMethodReturnTypeProvider::executeFakeCall(
                $source,
                $fake_method_call,
                $event->getContext(),
                $fakeProxy,
            );
        }

        return null;
    }

    /**
     * Return the formal params of Model::addGlobalScope with `static` pinned to the concrete
     * model class via TypeExpander::expandUnion final: true.
     *
     * Laravel's docblock uses Builder<static> in the Closure param position. Psalm expands
     * `static` to `ModelClass&static` for non-final classes, while the user's bare
     * `Builder $query` hint gets Builder<static> via template inference — the `&static`
     * intersection on the expected side causes the unification to fail (issue #1038).
     * Pinning with final: true resolves to the plain model class on both sides.
     *
     * @return list<FunctionLikeParameter>|null
     */
    private static function expandedGlobalScopeParams(Codebase $codebase, string $modelClass): ?array
    {
        if (\array_key_exists($modelClass, self::$globalScopeParamsCache)) {
            return self::$globalScopeParamsCache[$modelClass];
        }

        $methodId = new MethodIdentifier($modelClass, 'addglobalscope');
        if (!$codebase->methodExists($methodId)) {
            return self::$globalScopeParamsCache[$modelClass] = null;
        }

        // Fetch raw params from the declaring method storage directly, bypassing the
        // params provider. Going through getMethodParams() would re-enter this provider
        // (Methods::getMethodParams consults it before falling through to storage); the
        // only protection against infinite recursion would be the null-source guard above.
        // Using getStorage() makes the independence from the provider explicit.
        $declaringMethodId = $codebase->methods->getDeclaringMethodId($methodId);
        if (!$declaringMethodId instanceof \Psalm\Internal\MethodIdentifier) {
            return self::$globalScopeParamsCache[$modelClass] = null;
        }

        return self::$globalScopeParamsCache[$modelClass] = \array_map(
            static function (FunctionLikeParameter $param) use ($codebase, $modelClass): FunctionLikeParameter {
                if (!$param->type instanceof Union) {
                    return $param;
                }

                // setType() clones — never mutates the shared method storage params.
                return $param->setType(TypeExpander::expandUnion(
                    $codebase,
                    $param->type,
                    $modelClass,
                    $modelClass,
                    null,
                    evaluate_class_constants: true,
                    evaluate_conditional_types: false,
                    // Resolves `static` to the plain model class instead of
                    // the ModelClass&static intersection. In a closure param
                    // (accept/contravariant position) the intersection over-rejects
                    // because the provided Builder<static> cannot be proven to
                    // contain Builder<ModelClass&static>. final: true prevents that.
                    final: true,
                ));
            },
            $codebase->methods->getStorage($declaringMethodId)->params,
        );
    }

    /**
     * Get method params, appending a synthetic variadic rest parameter when needed.
     *
     * Methods like Query\Builder::select() use @psalm-variadic (internally func_get_args())
     * which sets MethodStorage::$variadic = true. But getMethodParams() returns formal params
     * without a variadic parameter. When these methods are called statically on Models via
     * __callStatic, Psalm checks arity against our provided params and emits TooManyArguments.
     *
     * This mirrors the storage-level variadic flag by appending a synthetic variadic rest
     * parameter to the returned param list so Psalm allows extra args.
     *
     * @internal Used by {@see \Psalm\LaravelPlugin\Handlers\Magic\MethodForwardingHandler}
     * @return list<FunctionLikeParameter>
     */
    public static function getParamsWithVariadicFlag(Codebase $codebase, MethodIdentifier $methodId): array
    {
        $params = $codebase->methods->getMethodParams($methodId);

        try {
            $storage = $codebase->methods->getStorage($methodId);
        } catch (\UnexpectedValueException) {
            // Method exists through @mixin but has no direct storage on the class
            return $params;
        }

        if ($storage->variadic) {
            // Append a synthetic variadic rest param instead of marking the last formal param.
            // Marking the last param as variadic would relax arity — e.g., addSelect($column)
            // would accept zero args. Appending preserves the required params while allowing extras.
            $params[] = new FunctionLikeParameter(
                name: 'args',
                by_ref: false,
                type: Type::getMixed(),
                is_variadic: true,
            );
        }

        return $params;
    }
}
