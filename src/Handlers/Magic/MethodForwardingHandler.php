<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Magic;

use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
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
use Psalm\Type\Atomic\TNamedObject;
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
     * Cache: "ModelClass:methodName" → bool (column matched).
     *
     * Keyed by model class + method name to avoid repeating the property-name normalisation
     * loop on subsequent calls with the same (model, method) pair.
     *
     * @var array<string, bool>
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
     * Cache: class name → whether it is an Eloquent model.
     *
     * @var array<string, bool>
     */
    private static array $modelClassCache = [];

    /** @psalm-external-mutation-free */
    public static function init(ForwardingRule $rule): void
    {
        self::$rule = $rule;
        self::$sourceClassIndex = [];
        self::$searchClassIndex = [];
        self::$scopeParamsCache = [];
        self::$modelClassCache = [];

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
            return self::resolveDynamicWhereOnRelation($codebase, $methodName, $fqClassName, $templateParams);
        }

        return null;
    }

    /**
     * Provide parameter types for methods resolved via __call on source classes.
     *
     * Only fires for Path 2 (QueryBuilder-only methods like orderBy, limit, groupBy).
     * Mixin-resolved methods (Path 1) already have params from the target class.
     *
     * When resolveDynamicWhereClauses is enabled, also provides a permissive variadic signature
     * for where{Column} methods so Psalm confirms they exist and doesn't emit
     * UndefinedMagicMethod or TooManyArguments. This path does not validate value types.
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

        $modelBuilderResult = self::resolveModelBuilderMixinInterception(
            $source,
            $event,
            $mixinTargetClass,
            $methodName,
            $codebase,
        );

        if ($modelBuilderResult instanceof Union) {
            return $modelBuilderResult;
        }

        if (!$stmt instanceof MethodCall) {
            return null;
        }

        // Get the ORIGINAL caller's type from the node type provider.
        // $stmt->var type is set BEFORE mixin resolution
        // (confirmed in MethodCallAnalyzer.php lines 67-69).
        $callerType = $source->getNodeTypeProvider()->getType($stmt->var);

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
     * Intercept Model's @mixin Builder<static> fluent calls after Builder stubs use $this/static.
     *
     * Psalm binds $this/static in mixin-reached methods to the mixin host, so without this
     * Customer::where() and (new Customer())->where() would be typed as Customer&static.
     */
    private static function resolveModelBuilderMixinInterception(
        StatementsAnalyzer $source,
        MethodReturnTypeProviderEvent $event,
        string $mixinTargetClass,
        string $methodName,
        Codebase $codebase,
    ): ?Union {
        if (\strtolower($mixinTargetClass) !== \strtolower(\Illuminate\Database\Eloquent\Builder::class)) {
            return null;
        }

        $modelClass = self::extractModelClassFromMixinCaller($source, $event, $codebase);

        if ($modelClass === null) {
            return null;
        }

        if (!ReturnTypeResolver::targetClassMethodReturnsSelf($codebase, \Illuminate\Database\Eloquent\Builder::class, $methodName)) {
            return null;
        }

        return new Union([ModelMethodHandler::builderTypeForModel($modelClass, $codebase)]);
    }

    /**
     * @return class-string<\Illuminate\Database\Eloquent\Model>|null
     */
    private static function extractModelClassFromMixinCaller(
        StatementsAnalyzer $source,
        MethodReturnTypeProviderEvent $event,
        Codebase $codebase,
    ): ?string {
        $stmt = $event->getStmt();

        if ($stmt instanceof StaticCall) {
            $calledClass = $event->getCalledFqClasslikeName();

            if (\is_string($calledClass) && self::isModelClass($codebase, $calledClass)) {
                return $calledClass;
            }

            return null;
        }

        $callerType = $source->getNodeTypeProvider()->getType($stmt->var);

        if (!$callerType instanceof Union) {
            return null;
        }

        foreach ($callerType->getAtomicTypes() as $atomicType) {
            if (!$atomicType instanceof TNamedObject) {
                continue;
            }

            if (isset(self::$sourceClassIndex[\strtolower($atomicType->value)])) {
                continue;
            }

            if (self::isModelClass($codebase, $atomicType->value)) {
                return $atomicType->value;
            }
        }

        return null;
    }

    /** @psalm-external-mutation-free */
    private static function isModelClass(Codebase $codebase, string $className): bool
    {
        $classNameLower = \strtolower($className);

        if (\array_key_exists($classNameLower, self::$modelClassCache)) {
            return self::$modelClassCache[$classNameLower];
        }

        if (\strtolower($className) === \strtolower(\Illuminate\Database\Eloquent\Model::class)) {
            return self::$modelClassCache[$classNameLower] = true;
        }

        return self::$modelClassCache[$classNameLower] = $codebase->classOrInterfaceExists($className)
            && $codebase->classExtends($className, \Illuminate\Database\Eloquent\Model::class);
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
     * Returns the relation's generic type (e.g. HasMany<Post, User>) when the method
     * name matches the where{Column} pattern and the column is declared on the related model
     * via @property annotations. Returns null otherwise (falling through to Psalm default).
     *
     * Column matching normalises both sides to lowercase-without-underscores so that:
     *   - whereTitle       → matches @property string $title
     *   - whereEmailAddress → matches @property string $email_address
     *   - whereFirstName   → matches @property string $first_name
     *
     * @param non-empty-list<Union>|list<Union>|null $templateParams Relation's template type parameters
     * @psalm-external-mutation-free
     */
    private static function resolveDynamicWhereOnRelation(
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

        if (!self::columnMatchesDynamicWhere($codebase, $modelClass, $methodName)) {
            return null;
        }

        // Column exists on the model → method is fluent, return the full Relation type.
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
     * Check if the column referenced by a dynamic where{Column} method exists on the model.
     *
     * The column suffix (method name minus "where") is compared against the model's
     * pseudo_property_get_types (populated from @property / @property-read annotations).
     *
     * Psalm lowercases all method names before passing them to providers, so the suffix is
     * already lowercase (e.g. "wherefirstname" → suffix "firstname"). The @property names
     * are normalised to the same form by stripping "$" and underscores and lowercasing, so:
     *   - whereTitle (→ "title") matches @property string $title (→ "title")
     *   - whereFirstName (→ "firstname") matches @property string $first_name (→ "firstname")
     *   - whereEmailAddress (→ "emailaddress") matches @property string $email_address (→ "emailaddress")
     *
     * Note: PHP method names use camelCase, so underscores in the suffix only arise for
     * non-standard calls that would not match Laravel's dynamicWhere convention anyway.
     *
     * @param class-string<\Illuminate\Database\Eloquent\Model> $modelClass
     * @psalm-external-mutation-free
     */
    private static function columnMatchesDynamicWhere(
        Codebase $codebase,
        string $modelClass,
        string $methodName,
    ): bool {
        $key = $modelClass . ':' . $methodName;

        if (\array_key_exists($key, self::$dynamicWhereCache)) {
            return self::$dynamicWhereCache[$key];
        }

        // Extract the column suffix: "wheretitle" → "title", "wherefirstname" → "firstname"
        $columnSuffix = \substr($methodName, 5);

        try {
            $storage = $codebase->classlike_storage_provider->get(\strtolower($modelClass));
        } catch (\InvalidArgumentException) {
            self::$dynamicWhereCache[$key] = false;
            return false;
        }

        // Use pseudo_property_get_types (from @property and @property-read) rather than
        // pseudo_property_set_types (@property-write). Dynamic WHERE filters on readable
        // database columns; @property-write only properties are write-only computed fields
        // (e.g. password hashing) that do not correspond to filterable columns.
        //
        // We do NOT cache the per-model normalised property set separately. Psalm may populate
        // pseudo_property_get_types lazily (adding entries as it parses the class), so a
        // snapshot taken on the first call could be incomplete for subsequent method checks.
        // The per-(model, method) $dynamicWhereCache still prevents redundant lookups.
        // "$first_name" → "firstname", "$title" → "title"
        foreach (array_keys($storage->pseudo_property_get_types) as $propName) {
            $normalized = \strtolower(\str_replace(['$', '_'], '', $propName));

            if ($normalized === $columnSuffix) {
                self::$dynamicWhereCache[$key] = true;
                return true;
            }
        }

        self::$dynamicWhereCache[$key] = false;
        return false;
    }
}
