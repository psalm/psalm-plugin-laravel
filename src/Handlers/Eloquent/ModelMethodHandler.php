<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Class_;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Util\ProxyMethodReturnTypeProvider;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
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
 * 5. afterClassLikeVisit — removes pseudo static methods so the plugin can handle them
 *
 * Existence, visibility, params, and return type providers for concrete Model subclasses are
 * registered dynamically per-model by {@see ModelRegistrationHandler} because Psalm's
 * provider lookup requires exact class name matching — a handler registered for Model::class
 * is not consulted for concrete subclasses like App\Models\User.
 *
 * The getClassLikeNames() registration for Model::class still handles Model::query()
 * and the __callStatic proxy for methods resolvable through the single-hop mixin chain.
 */
final class ModelMethodHandler implements MethodReturnTypeProviderInterface, AfterClassLikeVisitInterface
{
    /**
     * Cache for isUnresolvedBuilderMethod results.
     *
     * This method is called up to 4 times per static method call (existence, visibility,
     * params, return type), so caching avoids redundant methodExists lookups.
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
     * Reverse map: custom builder FQCN → model FQCN.
     *
     * Used by builder-level handlers to look up the model for a custom builder class.
     * Assumes 1:1 builder-to-model mapping — if two models share a builder, the last
     * registration wins. This is acceptable because shared builders are rare, and the
     * trait methods (SoftDeletes, etc.) are typically identical across such models.
     *
     * @var array<class-string<Builder>, class-string<Model>>
     */
    private static array $builderToModelMap = [];

    /**
     * Trait-declared builder methods for models with custom builders.
     *
     * When a model trait (e.g., SoftDeletes) declares @method static returning Builder<static>,
     * these methods are macro-registered on the builder at runtime via global scopes. For models
     * with custom builders, the pseudo_static_methods are removed from model storage so this
     * handler can provide the correct custom builder return type instead of the base Builder.
     *
     * @var array<class-string<Model>, array<lowercase-string, list<FunctionLikeParameter>>>
     */
    private static array $traitBuilderMethods = [];

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
        self::$builderToModelMap[$builderClass] = $modelClass;
    }

    /**
     * Register trait-declared builder methods for a model with a custom builder.
     *
     * Called by {@see ModelRegistrationHandler} after removing these methods from the
     * model's pseudo_static_methods so this handler controls both static model calls
     * and builder instance calls.
     *
     * @param class-string<Model> $modelClass
     * @param array<lowercase-string, list<FunctionLikeParameter>> $methods method name → params
     * @psalm-external-mutation-free
     */
    public static function registerTraitBuilderMethods(string $modelClass, array $methods): void
    {
        self::$traitBuilderMethods[$modelClass] = $methods;
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
     * Build the Psalm type for Builder<Model> (or CustomBuilder<Model>).
     *
     * If the custom builder has template parameters, returns TGenericObject (e.g. PostBuilder<Post>).
     * If the builder has no template params (e.g. `final class MemberBuilder extends Builder<Member>`),
     * returns a plain TNamedObject (just MemberBuilder) to avoid "too many template params" errors.
     *
     * @psalm-mutation-free
     */
    private static function builderType(string $builderClass, string $modelClass, Codebase $codebase): Type\Atomic\TNamedObject
    {
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

        if (!self::isUnresolvedBuilderMethod($codebase, $modelClass, $methodName)) {
            return null;
        }

        // Custom builder method — use its actual params (e.g., PostBuilder::wherePublished)
        $builderClass = self::getBuilderClassForModel($modelClass);
        if ($builderClass !== Builder::class) {
            /** @var lowercase-string $methodName */
            $customBuilderMethodId = new MethodIdentifier($builderClass, $methodName);
            if ($codebase->methodExists($customBuilderMethodId)) {
                return $codebase->methods->getMethodParams($customBuilderMethodId);
            }
        }

        // Trait-declared builder method — use stored params from the original @method annotation.
        if (isset(self::$traitBuilderMethods[$modelClass][$methodName])) {
            return self::$traitBuilderMethods[$modelClass][$methodName];
        }

        // Query\Builder method — use its actual params
        /** @var lowercase-string $methodName */
        $queryBuilderMethodId = new MethodIdentifier(QueryBuilder::class, $methodName);
        if ($codebase->methodExists($queryBuilderMethodId)) {
            return $codebase->methods->getMethodParams($queryBuilderMethodId);
        }

        // Scope method — params from the scope definition minus the first $query param.
        /** @var class-string<Model> $modelClass */
        return self::getScopeParams($codebase, $modelClass, $methodName);
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
        /** @var class-string<Model> $modelClass */
        if (BuilderScopeHandler::hasScopeMethod($codebase, $modelClass, $methodName)) {
            return new Union([self::builderType($builderClass, $calledClass, $codebase)]);
        }

        // Trait-declared builder methods (e.g., SoftDeletes::withTrashed): return custom builder type.
        if (isset(self::$traitBuilderMethods[$modelClass][$methodName])) {
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
        if ($builderClass !== Builder::class && $codebase->methodExists(new MethodIdentifier($builderClass, $methodName))) {
            return self::$unresolvedCache[$key] = true;
        }

        // Trait-declared builder methods (e.g., SoftDeletes::withTrashed, onlyTrashed).
        // These are @method static on model traits that return Builder<static>. For models
        // with custom builders, removed from pseudo_static_methods so we control the return type.
        if (isset(self::$traitBuilderMethods[$modelClass][$methodName])) {
            return self::$unresolvedCache[$key] = true;
        }

        // Scope methods (e.g., scopeActive → active, #[Scope] verified → verified).
        // These are defined on the model and forwarded via __callStatic → Builder.
        /** @var class-string<Model> $modelClass */
        return self::$unresolvedCache[$key] = BuilderScopeHandler::hasScopeMethod($codebase, $modelClass, $methodName);
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

        if (! \is_string($called_fq_classlike_name)) {
            return null;
        }

        // Model::query()
        if ($event->getMethodNameLowercase() === 'query') {
            $builderClass = self::getBuilderClassForModel($called_fq_classlike_name);

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

            $fake_method_call = new MethodCall(
                new Variable('builder'),
                $methodId->method_name,
                $event->getCallArgs(),
            );

            $fakeProxy = self::builderType($builderClass, $called_fq_classlike_name, $codebase);

            return ProxyMethodReturnTypeProvider::executeFakeCall($source, $fake_method_call, $event->getContext(), $fakeProxy);
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Builder-level handlers for trait-declared methods (e.g., SoftDeletes).
    // Registered per custom builder class by ModelRegistrationHandler so that
    // builder instance calls like Post::query()->withTrashed() resolve correctly.
    // -----------------------------------------------------------------------

    /**
     * Confirm trait-declared builder methods exist on custom builder instances.
     *
     * @psalm-external-mutation-free
     */
    public static function doesTraitMethodExistOnBuilder(MethodExistenceProviderEvent $event): ?bool
    {
        return self::hasTraitMethodOnBuilder($event->getFqClasslikeName(), $event->getMethodNameLowercase())
            ? true
            : null;
    }

    /**
     * Trait-declared builder methods forwarded via macros are effectively public.
     *
     * @psalm-external-mutation-free
     */
    public static function isTraitMethodVisibleOnBuilder(MethodVisibilityProviderEvent $event): ?bool
    {
        return self::hasTraitMethodOnBuilder($event->getFqClasslikeName(), $event->getMethodNameLowercase())
            ? true
            : null;
    }

    /**
     * Provide params for trait-declared builder methods on custom builder instances.
     *
     * @return list<FunctionLikeParameter>|null
     * @psalm-external-mutation-free
     */
    public static function getTraitMethodParamsOnBuilder(MethodParamsProviderEvent $event): ?array
    {
        /** @var class-string<Builder> $builderClass */
        $builderClass = $event->getFqClasslikeName();
        $modelClass = self::$builderToModelMap[$builderClass] ?? null;

        /** @var lowercase-string $methodName */
        $methodName = $event->getMethodNameLowercase();

        return $modelClass !== null
            ? (self::$traitBuilderMethods[$modelClass][$methodName] ?? null)
            : null;
    }

    /**
     * Provide return type for trait-declared builder methods on custom builder instances.
     *
     * @psalm-external-mutation-free
     */
    public static function getTraitMethodReturnTypeOnBuilder(MethodReturnTypeProviderEvent $event): ?Union
    {
        $source = $event->getSource();
        if (!$source instanceof StatementsAnalyzer) {
            return null;
        }

        /** @var class-string<Builder> $builderClass */
        $builderClass = $event->getFqClasslikeName();
        $modelClass = self::$builderToModelMap[$builderClass] ?? null;
        if ($modelClass === null) {
            return null;
        }

        if (!isset(self::$traitBuilderMethods[$modelClass][$event->getMethodNameLowercase()])) {
            return null;
        }

        return new Union([self::builderType($builderClass, $modelClass, $source->getCodebase())]);
    }

    /**
     * Check if a trait-declared builder method exists for the given custom builder class.
     *
     * @psalm-external-mutation-free
     */
    private static function hasTraitMethodOnBuilder(string $builderClass, string $methodName): bool
    {
        /** @var class-string<Builder> $builderClass */
        $modelClass = self::$builderToModelMap[$builderClass] ?? null;

        /** @var lowercase-string $methodName */
        return $modelClass !== null && isset(self::$traitBuilderMethods[$modelClass][$methodName]);
    }

    // -----------------------------------------------------------------------
    // Builder-level handlers for scope methods on custom builders.
    // Registered per custom builder class by ModelRegistrationHandler so that
    // builder instance calls like Post::query()->featured() resolve correctly.
    // See https://github.com/psalm/psalm-plugin-laravel/issues/630
    // -----------------------------------------------------------------------

    /**
     * Confirm scope methods exist on custom builder instances.
     *
     * When Post::query() returns PostBuilder<Post>, calling ->featured() triggers
     * a lookup on PostBuilder. This handler confirms the method exists by checking
     * if the associated model has a matching scope (legacy scopeXxx or #[Scope]).
     */
    public static function doesScopeMethodExistOnBuilder(MethodExistenceProviderEvent $event): ?bool
    {
        $source = $event->getSource();
        if (!$source instanceof StatementsSource) {
            return null;
        }

        return self::hasScopeOnBuilder($source->getCodebase(), $event->getFqClasslikeName(), $event->getMethodNameLowercase())
            ? true
            : null;
    }

    /**
     * Scope methods on custom builders are effectively public (invoked via __call magic).
     */
    public static function isScopeMethodVisibleOnBuilder(MethodVisibilityProviderEvent $event): ?bool
    {
        return self::hasScopeOnBuilder($event->getSource()->getCodebase(), $event->getFqClasslikeName(), $event->getMethodNameLowercase())
            ? true
            : null;
    }

    /**
     * Provide params for scope methods on custom builder instances.
     *
     * @return list<FunctionLikeParameter>|null
     */
    public static function getScopeMethodParamsOnBuilder(MethodParamsProviderEvent $event): ?array
    {
        $source = $event->getStatementsSource();
        if (!$source instanceof StatementsSource) {
            return null;
        }

        /** @var class-string<Builder> $builderClass */
        $builderClass = $event->getFqClasslikeName();
        $modelClass = self::$builderToModelMap[$builderClass] ?? null;
        if ($modelClass === null) {
            return null;
        }

        // getScopeParams returns null for non-scope methods, so no hasScopeMethod guard needed.
        // This avoids redundant methodExists calls (hasScopeMethod probes the same methods).
        return self::getScopeParams($source->getCodebase(), $modelClass, $event->getMethodNameLowercase());
    }

    /**
     * Provide return type for scope methods on custom builder instances.
     *
     * Returns CustomBuilder<Model> (e.g., PostBuilder<Post>) instead of the base
     * Builder<Model> that BuilderScopeHandler would return.
     */
    public static function getScopeMethodReturnTypeOnBuilder(MethodReturnTypeProviderEvent $event): ?Union
    {
        $source = $event->getSource();
        if (!$source instanceof StatementsAnalyzer) {
            return null;
        }

        /** @var class-string<Builder> $builderClass */
        $builderClass = $event->getFqClasslikeName();
        $modelClass = self::$builderToModelMap[$builderClass] ?? null;
        if ($modelClass === null) {
            return null;
        }

        $codebase = $source->getCodebase();
        if (!BuilderScopeHandler::hasScopeMethod($codebase, $modelClass, $event->getMethodNameLowercase())) {
            return null;
        }

        return new Union([self::builderType($builderClass, $modelClass, $codebase)]);
    }

    /**
     * Check if a scope method exists for the given custom builder class.
     *
     * Looks up the model associated with the builder, then delegates to
     * BuilderScopeHandler for scope detection.
     */
    private static function hasScopeOnBuilder(Codebase $codebase, string $builderClass, string $methodName): bool
    {
        /** @var class-string<Builder> $builderClass */
        $modelClass = self::$builderToModelMap[$builderClass] ?? null;
        if ($modelClass === null) {
            return false;
        }

        /** @var class-string<Model> $modelClass */
        return BuilderScopeHandler::hasScopeMethod($codebase, $modelClass, $methodName);
    }

    /**
     * Get params for a scope method on a model, minus the $query parameter.
     *
     * Handles both legacy scopeXxx() methods and modern #[Scope] attribute methods.
     * Used by both the static model call handler ({@see getMethodParams}) and the
     * custom builder instance handler ({@see getScopeMethodParamsOnBuilder}).
     *
     * @param class-string<Model> $modelClass
     * @return list<FunctionLikeParameter>|null
     */
    private static function getScopeParams(Codebase $codebase, string $modelClass, string $methodName): ?array
    {
        // Legacy: scopeActive(Builder $query, ...) → active(...)
        $legacyScopeMethod = $modelClass . '::scope' . \ucfirst($methodName);
        if ($codebase->methodExists($legacyScopeMethod)) {
            /** @var lowercase-string $legacyScopeLower */
            $legacyScopeLower = 'scope' . $methodName;

            return \array_slice(
                $codebase->methods->getMethodParams(new MethodIdentifier($modelClass, $legacyScopeLower)),
                1,
            );
        }

        // Modern #[Scope]: active(Builder $query, ...) → active(...)
        $directMethod = $modelClass . '::' . $methodName;
        if ($codebase->methodExists($directMethod)) {
            /** @var lowercase-string $methodName */
            return \array_slice(
                $codebase->methods->getMethodParams(new MethodIdentifier($modelClass, $methodName)),
                1,
            );
        }

        return null;
    }

    /** @inheritDoc */
    #[\Override]
    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event): void
    {
        $storage = $event->getStorage();
        if (
            $event->getStmt() instanceof Class_
            && !$storage->abstract
            && isset($storage->parent_classes[\strtolower(Model::class)])
        ) {
            unset(
                $storage->pseudo_static_methods['newmodelquery'],
                $storage->pseudo_static_methods['newquery'],
                $storage->pseudo_static_methods['query'],
            );
        }
    }
}
