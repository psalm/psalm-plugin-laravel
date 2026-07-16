<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Magic;

use Illuminate\Database\Eloquent\Builder;
use PhpParser\Node\Expr\MethodCall;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\LaravelPlugin\Handlers\Eloquent\BuilderScopeHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelMethodHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\DynamicWhereResolver;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\ModelPropertyResolver;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\RelatedBuilderMethodResolver;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\ResolvedForwardedMethod;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodParamsProviderInterface;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Union;

/**
 * Intercepts method calls on classes that forward to other classes via @mixin or __call,
 * and preserves the caller's generic type for fluent (self-returning) methods.
 *
 * Two resolution paths:
 * - Path 1 (Mixin interception): Psalm resolves a method via @mixin on the source class
 *   (e.g., HasOne -> @mixin Builder -> Builder::where). The handler fires for Builder,
 *   detects the original caller was a Relation, and returns the Relation's generic type.
 * - Path 2 (Direct __call): The method isn't found via @mixin (mixin is single-hop),
 *   so Psalm falls to __call on the source class. The handler fires for HasOne directly
 *   and resolves via the search classes.
 *
 * Also implements MethodParamsProvider for Path 2 methods (QueryBuilder-only methods
 * like orderBy, limit, groupBy) so Psalm can check arguments.
 *
 * Also resolves Laravel's dynamic where{Column} magic methods on relation chains
 * (e.g., $user->posts()->whereTitle('foo')) when resolveDynamicWhereClauses is enabled (default: true).
 * Column names are validated against the model's declared @property annotations;
 * unmatched columns fall through to mixed without an error. The dynamic-where helpers
 * (validation, segment splitting, typed-param hand-off cache) live in
 * {@see \Psalm\LaravelPlugin\Handlers\Eloquent\Support\DynamicWhereResolver}; this handler invokes them on the
 * relation-chain path while {@see \Psalm\LaravelPlugin\Handlers\Eloquent\ModelMethodHandler}
 * uses the same util for direct Model static/instance calls (issue #1000).
 * Disable via <resolveDynamicWhereClauses value="false" /> in psalm.xml.
 *
 * Also resolves model scope methods called on relation chains
 * (e.g., $user->posts()->published()->get() where Post::scopePublished() exists).
 * Both legacy scope{Name}() methods and modern #[Scope] attribute methods are supported.
 */
final class MethodForwardingHandler implements MethodReturnTypeProviderInterface, MethodParamsProviderInterface
{
    private static ?ForwardingRule $rule = null;

    /** @var array<lowercase-string, bool> Indexed source classes for O(1) lookup */
    private static array $sourceClassIndex = [];

    /** @var array<lowercase-string, bool> Indexed search classes for O(1) lookup in mixin interception */
    private static array $searchClassIndex = [];

    /**
     * Cache: "lowercase-relation-class::method" → related model class.
     *
     * Populated by resolveScopeOnRelation() when a scope is confirmed on the related model.
     * Consulted by getMethodParams() so Psalm can validate call arguments without crashing
     * on the missing HasMany::electric method storage.
     *
     * When the same method name is a scope on different related models via the same relation
     * class (e.g. HasMany<Vehicle>::electric and HasMany<Report>::electric), the last write
     * wins. In practice, scope methods with the same name have the same param structure,
     * so this is harmless.
     *
     * @var array<string, class-string<\Illuminate\Database\Eloquent\Model>>
     */
    private static array $scopeParamsCache = [];

    /**
     * Direct Relation::__call signature hand-off. The return-type provider resolves a
     * custom-builder or trait method immediately before Psalm asks this params provider
     * to validate the same call. Entries are consumed once to prevent cross-call leakage.
     *
     * @var array<string, list<FunctionLikeParameter>>
     */
    private static array $pendingBuilderMethodParams = [];

    /**
     * Reset all static state. Called once per Plugin::__construct in production, plus
     * by test fixtures that re-bootstrap the handler. Every cache MUST be cleared so
     * leftover entries from a previous run can't leak across boundaries — in particular
     * the {@see DynamicWhereResolver} hand-off cache, where a stale entry could be
     * consumed by a future call whose first Arg happens to share an spl_object_id.
     *
     * The DynamicWhereResolver enable flag is also cleared by the reset; Plugin
     * re-applies it from XML config after init() returns, so a true→false config flip
     * across re-bootstraps takes effect instead of inheriting the previous state.
     *
     * @psalm-external-mutation-free
     */
    public static function init(ForwardingRule $rule): void
    {
        self::$rule = $rule;
        self::$sourceClassIndex = [];
        self::$searchClassIndex = [];
        self::$scopeParamsCache = [];
        self::$pendingBuilderMethodParams = [];
        RelatedBuilderMethodResolver::reset();
        DynamicWhereResolver::reset();

        foreach ($rule->allSourceClasses() as $class) {
            self::$sourceClassIndex[\strtolower($class)] = true;
        }

        foreach ($rule->searchClasses as $class) {
            self::$searchClassIndex[\strtolower($class)] = true;
        }

        ReturnTypeResolver::initForRule($rule);
    }

    /**
     * @return list<string>
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        if (!self::$rule instanceof \Psalm\LaravelPlugin\Handlers\Magic\ForwardingRule) {
            return [];
        }

        $classes = self::$rule->allSourceClasses();

        if (self::$rule->interceptMixin) {
            $classes = \array_merge($classes, self::$rule->searchClasses);
        }

        /** @var list<string> */
        return \array_values(\array_unique($classes));
    }

    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $rule = self::$rule;

        if (!$rule instanceof \Psalm\LaravelPlugin\Handlers\Magic\ForwardingRule) {
            return null;
        }

        $source = $event->getSource();

        if (!$source instanceof StatementsAnalyzer) {
            return null;
        }

        $fqClassName = $event->getFqClasslikeName();
        $methodName = $event->getMethodNameLowercase();
        $codebase = $source->getCodebase();

        // PATH 1: Mixin interception (fqClassName is Builder or QueryBuilder).
        // Must run first — see spec "Path ordering" section for rationale.
        $mixinResult = self::handleMixinInterception($source, $event, $fqClassName, $methodName, $codebase);

        if ($mixinResult instanceof \Psalm\Type\Union) {
            return $mixinResult;
        }

        // Skip methods already in declaring_method_ids on the source class.
        // These have stub return types (@return $this) that Psalm resolves correctly.
        // Only needed for Path 2 (source class calls, not mixin target calls).
        if (self::isInDeclaringMethodIds($codebase, $fqClassName, $methodName)) {
            return null;
        }

        // PATH 2: Direct __call (fqClassName is HasOne, BelongsTo, etc.)
        $fqClassNameLower = \strtolower($fqClassName);

        if (!isset(self::$sourceClassIndex[$fqClassNameLower])) {
            return null;
        }

        $templateParams = $event->getTemplateTypeParameters() ?? self::extractTemplateParamsFromCaller(
            $source,
            $event,
            $fqClassName,
        );

        $relationAtomic = $templateParams !== null
            ? new TGenericObject($fqClassName, $templateParams)
            : null;

        // A real method introduced by the related model's custom builder wins over
        // Builder::__call and Query Builder forwarding, matching PHP dispatch.
        if ($relationAtomic instanceof TGenericObject) {
            $customBuilderResult = self::resolveDeclaredBuilderMethodOnRelation(
                $source,
                $event,
                $codebase,
                $methodName,
                $relationAtomic,
                true,
            );

            if ($customBuilderResult instanceof Union) {
                return $customBuilderResult;
            }
        }

        $resolved = ReturnTypeResolver::resolve($fqClassName, $templateParams, $codebase, $methodName);

        if ($resolved instanceof \Psalm\Type\Union) {
            return $resolved;
        }

        // Trait-provided fluent methods are runtime builder macros. Real Builder methods
        // above retain precedence; scopes remain the next fallback below.
        if ($relationAtomic instanceof TGenericObject) {
            $traitBuilderResult = self::resolveTraitBuilderMethodOnRelation(
                $codebase,
                $methodName,
                $relationAtomic,
                true,
            );

            if ($traitBuilderResult instanceof Union) {
                return $traitBuilderResult;
            }
        }

        // Scope method fallback for Path 2: check if the method is a scope on the related model.
        // Handles $relation->published() where the related model has scopePublished() or #[Scope] published().
        $scopeResult = self::resolveScopeOnRelation($codebase, $methodName, $fqClassName, $templateParams);

        if ($scopeResult instanceof Union) {
            return $scopeResult;
        }

        // Dynamic where{Column} fallback for Path 2 (opt-in).
        // This handles the case where the method arrives via __call rather than @mixin.
        if (
            DynamicWhereResolver::isEnabled()
            && $templateParams !== null
            && DynamicWhereResolver::isDynamicWhereMethod($methodName)
        ) {
            return self::resolveDynamicWhereOnRelation($event, $codebase, $methodName, $fqClassName, $templateParams);
        }

        return null;
    }

    /**
     * Provide parameter types for methods resolved via __call on source classes.
     *
     * Only fires for Path 2 (QueryBuilder-only methods like orderBy, limit, groupBy).
     * Mixin-resolved methods (Path 1) already have params from the target class.
     *
     * When resolveDynamicWhereClauses is enabled and the return-type provider has
     * resolved a scalar column on the related model for a single-argument call,
     * returns a typed param derived from the column type so Psalm's argument
     * checker can emit InvalidArgument / InvalidScalarArgument on type mismatch
     * (issue #928). All other shapes (0 or 2+ args, multi-segment, unknown column,
     * non-scalar column) fall back to a permissive variadic-mixed signature so
     * Psalm still confirms the call exists without spurious TooManyArguments.
     *
     * @return list<FunctionLikeParameter>|null
     */
    #[\Override]
    public static function getMethodParams(MethodParamsProviderEvent $event): ?array
    {
        if (!self::$rule instanceof \Psalm\LaravelPlugin\Handlers\Magic\ForwardingRule) {
            return null;
        }

        $fqClassName = $event->getFqClasslikeName();

        $fqClassNameLower = \strtolower($fqClassName);

        if (!isset(self::$sourceClassIndex[$fqClassNameLower])) {
            return null;
        }

        $statementsSource = $event->getStatementsSource();

        if (!$statementsSource instanceof StatementsAnalyzer) {
            return null;
        }

        $methodName = $event->getMethodNameLowercase();
        $codebase = $statementsSource->getCodebase();

        // Don't override params for methods declared on the source class (stubs)
        if (self::isInDeclaringMethodIds($codebase, $fqClassName, $methodName)) {
            return null;
        }

        // A direct custom-builder/trait signature resolved by the return provider takes
        // precedence over base Builder params (important for a custom override of a magic
        // Query Builder method). Consume once so another call cannot inherit this signature.
        $builderMethodKey = \strtolower($fqClassName) . '::' . $methodName;
        if (\array_key_exists($builderMethodKey, self::$pendingBuilderMethodParams)) {
            $parameters = self::$pendingBuilderMethodParams[$builderMethodKey];
            unset(self::$pendingBuilderMethodParams[$builderMethodKey]);

            return $parameters;
        }

        // Find method on search classes, return its params
        foreach (self::$rule->searchClasses as $targetClass) {
            try {
                $classStorage = $codebase->classlike_storage_provider->get(\strtolower($targetClass));
            } catch (\InvalidArgumentException) {
                continue;
            }

            $declaringId = $classStorage->declaring_method_ids[$methodName] ?? null;

            if ($declaringId !== null) {
                try {
                    return ModelMethodHandler::getParamsWithVariadicFlag($codebase, $declaringId);
                } catch (\InvalidArgumentException|\UnexpectedValueException) {
                    continue;
                }
            }
        }

        // Scope method on relation chain: return scope params to avoid an UnexpectedValueException
        // crash in Psalm when checkMethodArgs tries to look up params for HasMany::scopeName.
        // The model class is resolved from the cache populated by resolveScopeOnRelation().
        $scopeKey = \strtolower($fqClassName) . '::' . $methodName;

        if (isset(self::$scopeParamsCache[$scopeKey])) {
            // Use the scope's actual params when available; fall back to a permissive variadic
            // signature (same as the dynamic-where fallback) rather than returning [] (zero params),
            // which would emit misleading TooManyArguments for scopes that accept arguments.
            return (
                BuilderScopeHandler::getScopeParams(
                    $codebase,
                    self::$scopeParamsCache[$scopeKey],
                    $methodName,
                ) ?? DynamicWhereResolver::variadicMixedParams()
            );
        }

        // Dynamic where{Column}: provide a variadic mixed signature so Psalm's magic-method
        // handler can validate argument counts. Existence itself is confirmed by Relation's
        // __call; these params only govern argument arity checking.
        // A variadic signature accepts both single-column (whereTitle($v)) and multi-column
        // (whereFirstNameAndLastName($a, $b)) patterns without raising TooManyArguments.
        if (DynamicWhereResolver::isEnabled() && DynamicWhereResolver::isDynamicWhereMethod($methodName)) {
            // Issue #928: when the return-type provider resolved a scalar column on the
            // related model and the call has exactly one argument, return a typed param
            // so Psalm's argument checker can emit InvalidArgument / InvalidScalarArgument
            // on type mismatch. Everything else (multi-segment, unknown column, non-scalar
            // column, 0 or 2+ args) falls through to the permissive variadic-mixed
            // signature. {@see DynamicWhereResolver::consumeTypedParams} for the rationale.
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
     * Intercept method calls that Psalm resolved via @mixin on a source class.
     *
     * When $relation->where() is called on HasOne<Phone, User>, Psalm resolves
     * where() via @mixin Builder<Phone> and fires the provider for Builder.
     * This method detects the original caller (HasOne) from the node type provider
     * and returns the Relation's generic type instead of Builder's.
     *
     * @param lowercase-string $methodName
     */
    private static function handleMixinInterception(
        StatementsAnalyzer $source,
        MethodReturnTypeProviderEvent $event,
        string $mixinTargetClass,
        string $methodName,
        Codebase $codebase,
    ): ?Union {
        $rule = self::$rule;

        if (!$rule instanceof \Psalm\LaravelPlugin\Handlers\Magic\ForwardingRule || !$rule->interceptMixin) {
            return null;
        }

        $stmt = $event->getStmt();
        $callerType = $stmt instanceof MethodCall ? $source->getNodeTypeProvider()->getType($stmt->var) : null;

        if (!$stmt instanceof MethodCall) {
            return null;
        }

        // Get the ORIGINAL caller's type from the node type provider.
        // $stmt->var type is set BEFORE mixin resolution
        // (confirmed in MethodCallAnalyzer.php lines 67-69).
        if (!$callerType instanceof \Psalm\Type\Union) {
            return null;
        }

        $mixinTargetLower = \strtolower($mixinTargetClass);

        foreach ($callerType->getAtomicTypes() as $atomicType) {
            if (!$atomicType instanceof TGenericObject) {
                continue;
            }

            // Is this caller a registered source class?
            $callerClassLower = \strtolower($atomicType->value);

            if (!isset(self::$sourceClassIndex[$callerClassLower])) {
                continue;
            }

            // Is the mixin target one of our search classes?
            if (!isset(self::$searchClassIndex[$mixinTargetLower])) {
                continue;
            }

            // The Relation's @mixin only exposes the base Builder surface. Look through
            // the related model first so a declared custom-builder override follows PHP's
            // real method dispatch and so custom-only methods do not collapse to mixed.
            $customBuilderResult = self::resolveDeclaredBuilderMethodOnRelation(
                $source,
                $event,
                $codebase,
                $methodName,
                $atomicType,
                false,
            );

            if ($customBuilderResult instanceof Union) {
                return $customBuilderResult;
            }

            // Try declared fluent-method resolution first (e.g. where(), orderBy()).
            $resolved = ReturnTypeResolver::resolve(
                $atomicType->value,
                $atomicType->type_params,
                $codebase,
                $methodName,
            );

            if ($resolved instanceof \Psalm\Type\Union) {
                return $resolved;
            }

            $traitBuilderResult = self::resolveTraitBuilderMethodOnRelation(
                $codebase,
                $methodName,
                $atomicType,
                false,
            );

            if ($traitBuilderResult instanceof Union) {
                return $traitBuilderResult;
            }

            // Scope method fallback: check if the method is a scope on the related model.
            // Handles $relation->published() where Post::scopePublished() or #[Scope] published() exists.
            // TRelatedModel is always the first template parameter on Relation subclasses.
            $scopeResult = self::resolveScopeOnRelation(
                $codebase,
                $methodName,
                $atomicType->value,
                $atomicType->type_params,
            );

            if ($scopeResult instanceof Union) {
                return $scopeResult;
            }

            // Dynamic where{Column} fallback (opt-in): preserve the Relation's generic type
            // when the method is a valid dynamic where for the related model's column.
            // Pre-check the pattern here (mirrors Path 2 guard) to avoid entering the function
            // for non-where* methods (orderBy, limit, etc.) when dynamic where is enabled.
            if (DynamicWhereResolver::isEnabled() && DynamicWhereResolver::isDynamicWhereMethod($methodName)) {
                return self::resolveDynamicWhereOnRelation(
                    $event,
                    $codebase,
                    $methodName,
                    $atomicType->value,
                    $atomicType->type_params,
                );
            }

            // First matching source class found but method is non-fluent — let Psalm resolve.
            return null;
        }

        return null;
    }

    /**
     * Resolve a real custom-builder method and apply Relation::forwardDecoratedCallTo
     * semantics to its localized return type.
     *
     * @param lowercase-string $methodName
     */
    private static function resolveDeclaredBuilderMethodOnRelation(
        StatementsAnalyzer $source,
        MethodReturnTypeProviderEvent $event,
        Codebase $codebase,
        string $methodName,
        TGenericObject $relationAtomic,
        bool $storeParameters,
    ): ?Union {
        $modelClass = ModelPropertyResolver::extractModelFromUnion($relationAtomic->type_params[0] ?? null);
        if ($modelClass === null) {
            return null;
        }

        $resolved = RelatedBuilderMethodResolver::resolveDeclaredMethod(
            $codebase,
            $source,
            $modelClass,
            $methodName,
            $event->getCallArgs(),
        );

        if (!$resolved instanceof ResolvedForwardedMethod) {
            return null;
        }

        if ($storeParameters) {
            self::storePendingBuilderMethodParams($relationAtomic->value, $methodName, $resolved->parameters);
        }

        return self::decorateBuilderReturn($codebase, $resolved->returnType, $relationAtomic);
    }

    /**
     * Resolve a model-trait fluent builder pseudo-method on a relation.
     *
     * @param lowercase-string $methodName
     * @psalm-external-mutation-free
     */
    private static function resolveTraitBuilderMethodOnRelation(
        Codebase $codebase,
        string $methodName,
        TGenericObject $relationAtomic,
        bool $storeParameters,
    ): ?Union {
        $modelClass = ModelPropertyResolver::extractModelFromUnion($relationAtomic->type_params[0] ?? null);
        if ($modelClass === null) {
            return null;
        }

        $resolved = RelatedBuilderMethodResolver::resolveTraitMethod($codebase, $modelClass, $methodName);
        if (!$resolved instanceof ResolvedForwardedMethod) {
            return null;
        }

        if ($storeParameters) {
            self::storePendingBuilderMethodParams($relationAtomic->value, $methodName, $resolved->parameters);
        }

        return self::decorateBuilderReturn($codebase, $resolved->returnType, $relationAtomic);
    }

    /**
     * Laravel substitutes the Relation only when the forwarded result is the wrapped
     * builder instance. Static analysis cannot express object identity, so a Builder
     * subtype is the established fluent approximation; every non-builder union branch
     * remains untouched.
     *
     * @psalm-external-mutation-free
     */
    private static function decorateBuilderReturn(
        Codebase $codebase,
        Union $returnType,
        TGenericObject $relationAtomic,
    ): Union {
        $builder = $returnType->getBuilder();
        $changed = false;

        foreach ($returnType->getAtomicTypes() as $key => $atomicType) {
            if (!$atomicType instanceof \Psalm\Type\Atomic\TNamedObject) {
                continue;
            }

            $isBuilder = \strtolower($atomicType->value) === \strtolower(Builder::class);

            if (!$isBuilder) {
                try {
                    $isBuilder = $codebase->classExtendsOrImplements($atomicType->value, Builder::class);
                } catch (\InvalidArgumentException) {
                    $isBuilder = false;
                }
            }

            if (!$isBuilder) {
                continue;
            }

            $builder->removeType($key);
            $builder->addType($relationAtomic);
            $changed = true;
        }

        return $changed ? $builder->freeze() : $returnType;
    }

    /**
     * @param list<FunctionLikeParameter> $parameters
     * @psalm-external-mutation-free
     */
    private static function storePendingBuilderMethodParams(
        string $relationClass,
        string $methodName,
        array $parameters,
    ): void {
        self::$pendingBuilderMethodParams[\strtolower($relationClass) . '::' . $methodName] = $parameters;
    }

    /**
     * Extract template parameters from the caller's node type.
     *
     * Falls back to node type provider when event->getTemplateTypeParameters()
     * returns null (Path 2 __call dispatch doesn't always populate template params).
     *
     * @return non-empty-list<Union>|null
     */
    private static function extractTemplateParamsFromCaller(
        StatementsAnalyzer $source,
        MethodReturnTypeProviderEvent $event,
        string $expectedClass,
    ): ?array {
        $stmt = $event->getStmt();

        if (!$stmt instanceof MethodCall) {
            return null;
        }

        // Node type provider always has the type of $stmt->var — Psalm analyzes
        // left-to-right, inner-to-outer, so the receiver is analyzed before
        // the method call's return type provider fires.
        $varType = $source->getNodeTypeProvider()->getType($stmt->var);

        if (!$varType instanceof \Psalm\Type\Union) {
            return null;
        }

        $expectedLower = \strtolower($expectedClass);

        foreach ($varType->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TGenericObject && \strtolower($atomic->value) === $expectedLower) {
                return $atomic->type_params;
            }
        }

        return null;
    }

    /**
     * Check if a method is in the class's declaring_method_ids (i.e., declared or inherited,
     * not resolved via __call or @mixin).
     *
     * @psalm-mutation-free
     */
    private static function isInDeclaringMethodIds(Codebase $codebase, string $class, string $method): bool
    {
        try {
            $storage = $codebase->classlike_storage_provider->get(\strtolower($class));

            return isset($storage->declaring_method_ids[$method]);
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Attempt to resolve a dynamic where{Column} method call on a relation.
     *
     * Returns the relation's generic type (e.g. HasMany<Post, User>) when every
     * segment of the where suffix corresponds to a declared @property on the related
     * model. Returns null otherwise (falling through to Psalm default).
     *
     * The suffix is split on `(?:And|Or)(?=[A-Z])` (Larastan parity), so multi-segment
     * forms like `whereFirstNameAndLastName($a, $b)` and `whereTitleOrSlug($x)` are
     * recognised. Splitting requires the ORIGINAL camel-cased method name from the
     * AST — Psalm lowercases the method name before invoking providers, which would
     * erase every `And`/`Or` boundary and collapse all multi-segment calls to a
     * single unrecognised token (see issue #927).
     *
     * Each segment is normalised (lowercased, `$` and `_` stripped) and compared
     * against `pseudo_property_get_types`, so:
     *   - whereTitle              → ['Title']                        → @property $title
     *   - whereEmailAddress       → ['EmailAddress']                  → @property $email_address
     *   - whereFirstNameAndLastName → ['FirstName', 'LastName']        → both @property
     *   - whereTitleOrSlug        → ['Title', 'Slug']                  → both @property
     *
     * For SINGLE-segment scalar-typed columns, the column type is queued via
     * {@see DynamicWhereResolver::storePendingColumnType} so {@see getMethodParams}
     * can return typed params for argument validation (issue #928). Multi-segment
     * calls have one argument per segment with different types, which doesn't fit the
     * single-typed-param hand-off; they get the variadic-mixed fallback.
     *
     * @param non-empty-list<Union>|list<Union>|null $templateParams Relation's template type parameters
     */
    private static function resolveDynamicWhereOnRelation(
        MethodReturnTypeProviderEvent $event,
        Codebase $codebase,
        string $methodName,
        string $relationClass,
        ?array $templateParams,
    ): ?Union {
        if ($templateParams === null || $templateParams === []) {
            return null;
        }

        // TRelatedModel is always the first template parameter on Relation subclasses.
        $modelClass = ModelPropertyResolver::extractModelFromUnion($templateParams[0]);

        if ($modelClass === null) {
            return null;
        }

        $stmt = $event->getStmt();

        $originalMethodName = DynamicWhereResolver::originalMethodName($stmt, $methodName);

        $columnType = DynamicWhereResolver::resolveColumnType($codebase, $modelClass, $originalMethodName);

        if ($columnType === false) {
            return null;
        }

        // Hand off the scalar column type to getMethodParams() so it can build a typed
        // parameter list and let Psalm validate arguments. See
        // {@see DynamicWhereResolver::storePendingColumnType} for the lifecycle. Issue #928.
        //
        // Gates on the store:
        //   (a) Path 1 (mixin interception) writes are unconsumable — Builder is in
        //       searchClassIndex, not sourceClassIndex, so our MethodParamsProvider
        //       early-returns for it and the entry would leak. Only store when the event
        //       fires for the relation class itself (Path 2 / direct __call).
        //   (b) Only single-segment scalar columns produce a Union here; multi-segment
        //       and non-scalar single-segment results were cached as `null` upstream.
        //   (c) Only enqueue when the call has exactly one argument — the only shape
        //       DynamicWhereResolver::consumeTypedParams() actually consumes. Without
        //       this gate, 2+ arg calls would deposit entries that the consumer skips,
        //       leaking across the analysis run (and risking a wrong-type lookup later
        //       if PHP reuses the spl_object_id of a freed Arg node).
        if (
            $columnType instanceof Union
            && \strtolower($event->getFqClasslikeName()) === \strtolower($relationClass)
            && $stmt instanceof MethodCall
        ) {
            $args = $stmt->getArgs();

            if (\count($args) === 1) {
                DynamicWhereResolver::storePendingColumnType($methodName, $args[0], $columnType);
            }
        }

        // Every segment matched → method is fluent, return the full Relation type.
        return new Union([
            new TGenericObject($relationClass, $templateParams),
        ]);
    }

    /**
     * Attempt to resolve a scope method call on a relation chain.
     *
     * Returns the relation's generic type (e.g. HasMany<Post, User>) when the method
     * name matches a scope defined on the related model — either a legacy scopeXxx() method
     * (e.g. scopePublished → published()) or a modern #[Scope] attribute method.
     * Returns null otherwise, letting Psalm fall through to its default handling.
     *
     * @param non-empty-list<Union>|list<Union>|null $templateParams Relation's template type parameters
     */
    private static function resolveScopeOnRelation(
        Codebase $codebase,
        string $methodName,
        string $relationClass,
        ?array $templateParams,
    ): ?Union {
        if ($templateParams === null || $templateParams === []) {
            return null;
        }

        // TRelatedModel is always the first template parameter on Relation subclasses.
        $modelClass = ModelPropertyResolver::extractModelFromUnion($templateParams[0]);

        if ($modelClass === null) {
            return null;
        }

        if (!BuilderScopeHandler::hasScopeMethod($codebase, $modelClass, $methodName)) {
            return null;
        }

        // Populate params cache so getMethodParams() can provide params without crashing.
        // Keyed by lowercase relation class + method to match the params provider lookup.
        $cacheKey = \strtolower($relationClass) . '::' . $methodName;
        self::$scopeParamsCache[$cacheKey] = $modelClass;

        // Scope exists on the related model → method is fluent: the Relation type is the
        // `?? $this` fallback. On a relation chain Laravel's callScope returns the wrapped
        // query for a null result, and Relation::__call maps that back to $this (the Relation),
        // so a value-returning scope surfaces `declared | Relation` while a void/fluent scope
        // keeps the full Relation type (issue #1053).
        $relationFallback = new Union([
            new TGenericObject($relationClass, $templateParams),
        ]);

        return BuilderScopeHandler::forwardedScopeReturnType($codebase, $modelClass, $methodName, $relationFallback);
    }
}
