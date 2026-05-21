<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Magic;

use PhpParser\Node\Expr\MethodCall;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\LaravelPlugin\Handlers\Eloquent\BuilderScopeHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelMethodHandler;
use Psalm\LaravelPlugin\Util\ModelPropertyResolver;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodParamsProviderInterface;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type;
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
 * unmatched columns fall through to mixed without an error.
 * Disable via <resolveDynamicWhereClauses value="false" /> in psalm.xml.
 *
 * Also resolves model scope methods called on relation chains
 * (e.g., $user->posts()->published()->get() where Post::scopePublished() exists).
 * Both legacy scope{Name}() methods and modern #[Scope] attribute methods are supported.
 */
final class MethodForwardingHandler implements
    MethodReturnTypeProviderInterface,
    MethodParamsProviderInterface
{
    /**
     * Larastan parity: split a where{Column} suffix on And/Or boundaries followed by an
     * uppercase letter. Mirrors `Illuminate\Database\Query\Builder::dynamicWhere`'s
     * `(And|Or)(?=[A-Z])`; we use a non-capturing group since we don't need the connector.
     */
    private const SEGMENT_SPLIT_PATTERN = '/(?:And|Or)(?=[A-Z])/';

    private static ?ForwardingRule $rule = null;

    /** @var array<lowercase-string, bool> Indexed source classes for O(1) lookup */
    private static array $sourceClassIndex = [];

    /** @var array<lowercase-string, bool> Indexed search classes for O(1) lookup in mixin interception */
    private static array $searchClassIndex = [];

    /**
     * Whether dynamic where{Column} method resolution is enabled.
     *
     * When enabled, methods matching the pattern where{Column} (e.g. whereTitle, whereEmail)
     * are resolved on relation chains when the column exists in the model's declared property annotations.
     */
    private static bool $enableDynamicWhere = false;

    /**
     * Cache: "ModelClass:originalMethodName" → three-state validation result.
     *
     * Keyed by model class + ORIGINAL-CASE method name (not lowercase). The original case
     * is needed because Laravel's runtime `Builder::dynamicWhere` splits the suffix on
     * `(?:And|Or)(?=[A-Z])`, which only fires on properly camel-cased boundaries. The same
     * lowercase name can therefore come from a splittable call (`whereFooAndBar`) or a
     * non-splittable one (`wherefooandbar`), and they validate differently.
     *
     * Values:
     *   - `false`: validation failed (one or more segments don't match a declared
     *     pseudo property). Caller falls through to mixed.
     *   - `null`: validation passed but no scalar column type can be handed off to the
     *     typed-parameter checker (multi-segment call, or single-segment whose type is
     *     object/array — Carbon, BackedEnum, json casts).
     *   - `Union`: validation passed AND the call is single-segment with a scalar column
     *     type. The typed-param hand-off path (issue #928) consumes this.
     *
     * @var array<string, false|null|Union>
     */
    private static array $dynamicWhereCache = [];

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
     * Hand-off cache: spl_object_id of the call's first {@see \PhpParser\Node\Arg} → column type.
     *
     * Populated by {@see resolveDynamicWhereOnRelation} during return-type resolution and
     * consumed by {@see getMethodParams} immediately after.
     *
     * Lifecycle (verified against vendor/vimeo/psalm/src/Psalm/Internal/Analyzer/
     * Statements/Expression/Call/Method/MissingMethodCallHandler.php):
     *   1. `handleMagicMethod` line 70 invokes the return-type provider — we store.
     *   2. Line 94 invokes `CallAnalyzer::checkMethodArgs` synchronously in the same
     *      worker; this routes through `Methods::getMethodParams` and fires our params
     *      provider, which consumes and `unset()`s the entry.
     *
     * The key is `methodName . ':' . spl_object_id($firstArg)`. Both events read args
     * from the same `$stmt->getArgs()` array, so the {@see \PhpParser\Node\Arg} nodes
     * are object-identical and the id matches across the producer/consumer pair. The
     * method-name prefix defends against PHP recycling an spl_object_id from a freed Arg
     * node into an unrelated later call — without it, the consumer could pick up a stale
     * column type from a different where{Column} method whose Arg node had the same id.
     *
     * Issue #928: the variadic-mixed signature previously returned by getMethodParams()
     * accepted any value type. With this hand-off, getMethodParams returns typed params
     * derived from the resolved column type and lets Psalm's standard argument checker
     * emit InvalidArgument / InvalidScalarArgument on type mismatch.
     *
     * @var array<string, Union>
     */
    private static array $pendingDynamicWhereColumnType = [];

    /**
     * Reset all static state. Called once per Plugin::__construct in production, plus
     * by test fixtures that re-bootstrap the handler. Every cache MUST be cleared so
     * leftover entries from a previous run can't leak across boundaries — in particular
     * $pendingDynamicWhereColumnType, where a stale entry could be consumed by a future
     * call whose first Arg happens to share an spl_object_id.
     *
     * @psalm-external-mutation-free
     */
    public static function init(ForwardingRule $rule): void
    {
        self::$rule = $rule;
        self::$sourceClassIndex = [];
        self::$searchClassIndex = [];
        self::$scopeParamsCache = [];
        self::$dynamicWhereCache = [];
        self::$pendingDynamicWhereColumnType = [];

        foreach ($rule->allSourceClasses() as $class) {
            self::$sourceClassIndex[\strtolower($class)] = true;
        }

        foreach ($rule->searchClasses as $class) {
            self::$searchClassIndex[\strtolower($class)] = true;
        }

        ReturnTypeResolver::initForRule($rule);
    }

    /**
     * Enable resolution of dynamic where{Column} methods on relation chains.
     *
     * Called from Plugin::registerHandlers() when <resolveDynamicWhereClauses value="true" /> is set.
     * Must be called after init() if called at all.
     *
     * @psalm-external-mutation-free
     */
    public static function enableDynamicWhere(): void
    {
        self::$enableDynamicWhere = true;
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

        $templateParams = $event->getTemplateTypeParameters()
            ?? self::extractTemplateParamsFromCaller($source, $event, $fqClassName);

        $resolved = ReturnTypeResolver::resolve(
            $fqClassName,
            $templateParams,
            $codebase,
            $methodName,
        );

        if ($resolved instanceof \Psalm\Type\Union) {
            return $resolved;
        }

        // Scope method fallback for Path 2: check if the method is a scope on the related model.
        // Handles $relation->published() where the related model has scopePublished() or #[Scope] published().
        $scopeResult = self::resolveScopeOnRelation($codebase, $methodName, $fqClassName, $templateParams);

        if ($scopeResult instanceof Union) {
            return $scopeResult;
        }

        // Dynamic where{Column} fallback for Path 2 (opt-in).
        // This handles the case where the method arrives via __call rather than @mixin.
        if (self::$enableDynamicWhere && $templateParams !== null && self::isDynamicWhereMethod($methodName)) {
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
            return ModelMethodHandler::getScopeParams($codebase, self::$scopeParamsCache[$scopeKey], $methodName)
                ?? [new FunctionLikeParameter('args', by_ref: false, type: Type::getMixed(), is_variadic: true)];
        }

        // Dynamic where{Column}: provide a variadic mixed signature so Psalm's magic-method
        // handler can validate argument counts. Existence itself is confirmed by Relation's
        // __call; these params only govern argument arity checking.
        // A variadic signature accepts both single-column (whereTitle($v)) and multi-column
        // (whereFirstNameAndLastName($a, $b)) patterns without raising TooManyArguments.
        if (self::$enableDynamicWhere && self::isDynamicWhereMethod($methodName)) {
            // Issue #928: when the return-type provider resolved a scalar column on the
            // related model and the call has exactly one argument, return a typed param
            // so Psalm's argument checker can emit InvalidArgument / InvalidScalarArgument
            // on type mismatch. Everything else (multi-segment, unknown column, non-scalar
            // column, 0 or 2+ args) falls through to the permissive variadic-mixed
            // signature. {@see consumeDynamicWhereTypedParams} for the full rationale.
            $typedParams = self::consumeDynamicWhereTypedParams($methodName, $event->getCallArgs());

            if ($typedParams !== null) {
                return $typedParams;
            }

            return [new FunctionLikeParameter('args', by_ref: false, type: Type::getMixed(), is_variadic: true)];
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
        $callerType = $stmt instanceof MethodCall
            ? $source->getNodeTypeProvider()->getType($stmt->var)
            : null;

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
            if (self::$enableDynamicWhere && self::isDynamicWhereMethod($methodName)) {
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
            if (
                $atomic instanceof TGenericObject
                && \strtolower($atomic->value) === $expectedLower
            ) {
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
     * For SINGLE-segment scalar-typed columns, the column type is queued in
     * {@see $pendingDynamicWhereColumnType} so {@see getMethodParams} can return
     * typed params for argument validation (issue #928). Multi-segment calls have
     * one argument per segment with different types, which doesn't fit the
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

        $originalMethodName = self::originalMethodName($stmt, $methodName);

        $columnType = self::resolveDynamicWhereColumnType($codebase, $modelClass, $originalMethodName);

        if ($columnType === false) {
            return null;
        }

        // Hand off the scalar column type to getMethodParams() so it can build a typed
        // parameter list and let Psalm validate arguments. See $pendingDynamicWhereColumnType
        // for the lifecycle and rationale. Issue #928.
        //
        // Gates on the store:
        //   (a) Path 1 (mixin interception) writes are unconsumable — Builder is in
        //       searchClassIndex, not sourceClassIndex, so our MethodParamsProvider
        //       early-returns for it and the entry would leak. Only store when the event
        //       fires for the relation class itself (Path 2 / direct __call).
        //   (b) Only single-segment scalar columns produce a Union here; multi-segment
        //       and non-scalar single-segment results were cached as `null` upstream.
        //   (c) Only enqueue when the call has exactly one argument — the only shape
        //       consumeDynamicWhereTypedParams() actually consumes. Without this gate,
        //       2+ arg calls would deposit entries that the consumer skips, leaking
        //       across the analysis run (and risking a wrong-type lookup later if PHP
        //       reuses the spl_object_id of a freed Arg node).
        if (
            $columnType instanceof Union
            && \strtolower($event->getFqClasslikeName()) === \strtolower($relationClass)
            && $stmt instanceof MethodCall
        ) {
            $args = $stmt->getArgs();

            if (\count($args) === 1) {
                self::$pendingDynamicWhereColumnType[$methodName . ':' . \spl_object_id($args[0])] = $columnType;
            }
        }

        // Every segment matched → method is fluent, return the full Relation type.
        return new Union([
            new TGenericObject($relationClass, $templateParams),
        ]);
    }

    /**
     * Read and remove the column type queued by {@see resolveDynamicWhereOnRelation},
     * returning a typed single-parameter list when the call has exactly one argument.
     * Returns null otherwise so the caller falls back to the permissive variadic-mixed
     * signature.
     *
     * Only the 1-argument value form is type-checked. Laravel's runtime
     * `Builder::dynamicWhere` always uses `=` as the operator and silently drops every
     * argument past the first (see `Query\Builder::addDynamic`), so the 2-arg "operator
     * form" (`whereTitle('=', 'foo')`) is a runtime bug. Rather than blessing it (which
     * would over-accept invalid types in the value position) or rejecting it (which
     * would surface as TooManyArguments on legacy code patterns that issue #928's
     * caveats explicitly ask us to tolerate), we leave 2+ arg calls to the variadic
     * fallback. Larastan does the same via a single-optional-mixed-variadic
     * `DynamicWhereParameterReflection`.
     *
     * @param list<\PhpParser\Node\Arg>|null $callArgs
     * @return list<FunctionLikeParameter>|null
     * @psalm-external-mutation-free
     */
    private static function consumeDynamicWhereTypedParams(string $methodName, ?array $callArgs): ?array
    {
        if ($callArgs === null || \count($callArgs) !== 1) {
            return null;
        }

        $key = $methodName . ':' . \spl_object_id($callArgs[0]);

        if (!isset(self::$pendingDynamicWhereColumnType[$key])) {
            return null;
        }

        $columnType = self::$pendingDynamicWhereColumnType[$key];
        unset(self::$pendingDynamicWhereColumnType[$key]);

        return [new FunctionLikeParameter('value', by_ref: false, type: $columnType, is_optional: false)];
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

        // Scope exists on the related model → method is fluent, return the full Relation type.
        return new Union([
            new TGenericObject($relationClass, $templateParams),
        ]);
    }

    /**
     * Check whether a method name looks like a Laravel dynamic where{Column} call.
     *
     * Method names are always lowercased by Psalm. The pattern requires the method
     * to start with "where" and have at least one more character (e.g. "wheretitle").
     * Methods that exactly equal "where" are skipped — they are declared on Builder.
     *
     * @psalm-pure
     */
    private static function isDynamicWhereMethod(string $methodName): bool
    {
        return \strlen($methodName) > 5 && \str_starts_with($methodName, 'where');
    }

    /**
     * Pull the original camel-cased method name from the AST, falling back to the
     * already-lowercased event method name when the call uses a dynamic name
     * (e.g. `$x->{$var}()`) or is a StaticCall. The camel case matters because
     * the And/Or segment split requires uppercase boundaries that Psalm strips
     * from getMethodNameLowercase().
     *
     * Psalm's MethodCallAnalyzer short-circuits dynamic-name MethodCalls before
     * invoking return-type providers, so the fallback branch is effectively dead
     * for the MethodCall case; kept for StaticCall and future Psalm versions.
     *
     * @psalm-mutation-free
     */
    private static function originalMethodName(
        \PhpParser\Node\Expr\MethodCall|\PhpParser\Node\Expr\StaticCall $stmt,
        string $lowercaseFallback,
    ): string {
        return $stmt instanceof MethodCall && $stmt->name instanceof \PhpParser\Node\Identifier
            ? $stmt->name->name
            : $lowercaseFallback;
    }

    /**
     * Validate a dynamic where{Column} method call against the model's declared @property
     * entries and return the column type when (and only when) a single scalar column was
     * matched.
     *
     * Mirrors Larastan's `BuilderHelper::dynamicWhere` (split on And/Or capitalised boundaries,
     * every segment must correspond to a declared column). The suffix after "where" is split
     * by `(?:And|Or)(?=[A-Z])`, so the ORIGINAL camel-cased method name from the AST is
     * required — see the caller for why we pull it from $stmt->name rather than the
     * lowercased event method name.
     *
     * @property entries are normalised by stripping `$` and underscores and lowercasing
     * (so `$email_address` collapses to `emailaddress`); each segment goes through the
     * same normalisation and the call is rejected on the first mismatch.
     *
     * Return values:
     *   - `false`: at least one segment doesn't match a declared property. Caller skips
     *     the dynamic where resolution entirely.
     *   - `null`: every segment matched but no scalar column type is suitable for the
     *     typed-parameter hand-off (multi-segment, or single-segment whose type contains
     *     an object/array — Carbon, BackedEnum, json casts). Caller returns the Relation
     *     type without populating the hand-off cache.
     *   - `Union`: every segment matched, the call is single-segment, and the column
     *     type is scalar. Caller queues this type for {@see getMethodParams} (issue #928).
     *
     * @param class-string<\Illuminate\Database\Eloquent\Model> $modelClass
     * @psalm-external-mutation-free
     */
    private static function resolveDynamicWhereColumnType(
        Codebase $codebase,
        string $modelClass,
        string $originalMethodName,
    ): false|Union|null {
        $key = $modelClass . ':' . $originalMethodName;

        if (\array_key_exists($key, self::$dynamicWhereCache)) {
            return self::$dynamicWhereCache[$key];
        }

        // Strip "where" / "WHERE" prefix — original-case may be anything from "Where" to "WHERE",
        // but our isDynamicWhereMethod gate ran on the lowercase form so the first 5 chars are
        // always "where" in some casing. substr is byte-safe for that ASCII prefix.
        $suffix = \substr($originalMethodName, 5);

        $segments = \preg_split(self::SEGMENT_SPLIT_PATTERN, $suffix);

        // preg_split with this literal pattern cannot fail at runtime; the guard is
        // a Psalm narrowing concession (return type is non-empty-list<string>|false).
        if ($segments === false) {
            return self::$dynamicWhereCache[$key] = false;
        }

        try {
            $storage = $codebase->classlike_storage_provider->get(\strtolower($modelClass));
        } catch (\InvalidArgumentException) {
            return self::$dynamicWhereCache[$key] = false;
        }

        // Build a normalised property map once per (model, method) pair. Cheaper than
        // re-running str_replace+strtolower over every property for every segment.
        // The map is not memoized across calls: Psalm may populate pseudo_property_get_types
        // lazily as it parses the class, and a stale snapshot could miss properties added
        // after first invocation.
        //
        // pseudo_property_get_types is sourced from @property/@property-read — readable
        // columns. @property-write entries (password hashing etc.) don't correspond to
        // filterable columns and are intentionally excluded.
        $normalizedProperties = [];
        foreach ($storage->pseudo_property_get_types as $propName => $propType) {
            $normalizedProperties[\strtolower(\str_replace(['$', '_'], '', $propName))] = $propType;
        }

        // Validate every segment and stash the matching column type. An empty segment
        // (e.g. `whereAndFoo` splitting to ['', 'Foo']) or any unmatched column rejects
        // the whole call.
        $matchedColumnTypes = [];
        foreach ($segments as $segment) {
            $segmentKey = \strtolower($segment);

            if ($segmentKey === '' || !isset($normalizedProperties[$segmentKey])) {
                return self::$dynamicWhereCache[$key] = false;
            }

            $matchedColumnTypes[] = $normalizedProperties[$segmentKey];
        }

        // Multi-segment calls take one argument per segment with potentially different
        // column types — no single Union can stand in for the typed-param hand-off.
        if (\count($matchedColumnTypes) !== 1) {
            return self::$dynamicWhereCache[$key] = null;
        }

        $columnType = $matchedColumnTypes[0];

        // Object/array column types (Carbon, BackedEnum, json casts) skip the typed-param
        // hand-off — Laravel coerces strings/ints to these at the query layer, so narrowing
        // to the property type would mass-regress real codebases.
        if ($columnType->hasObjectType() || $columnType->hasArray()) {
            return self::$dynamicWhereCache[$key] = null;
        }

        return self::$dynamicWhereCache[$key] = $columnType;
    }

}
