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
     * via #[UseEloquentBuilder] attribute (Laravel 12+) or newEloquentBuilder() override.
     * Used to return the correct builder type from query(), __callStatic, and scope methods.
     *
     * @var array<class-string<Model>, class-string<Builder>>
     */
    private static array $customBuilderMap = [];

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

        // Query\Builder method — use its actual params
        /** @var lowercase-string $methodName */
        $queryBuilderMethodId = new MethodIdentifier(QueryBuilder::class, $methodName);
        if ($codebase->methodExists($queryBuilderMethodId)) {
            return $codebase->methods->getMethodParams($queryBuilderMethodId);
        }

        // Scope method — params from the scope definition minus the first $query param.
        // Use string-based methodExists (like BuilderScopeHandler) so Psalm handles
        // case normalization. Then create MethodIdentifier with lowercase for getMethodParams.

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
            return \array_slice(
                $codebase->methods->getMethodParams(new MethodIdentifier($modelClass, $methodName)),
                1,
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

        $builderClass = self::getBuilderClassForModel($calledClass);

        // Scope methods: return Builder<Model> directly.
        // Using executeFakeCall for scopes doesn't work reliably because the scope
        // is resolved via Builder's __call magic which may fail in a fake call context.
        /** @var class-string<Model> $modelClass */
        if (BuilderScopeHandler::hasScopeMethod($codebase, $modelClass, $methodName)) {
            return new Union([
                new Type\Atomic\TGenericObject($builderClass, [
                    new Union([new Type\Atomic\TNamedObject($calledClass)]),
                ]),
            ]);
        }

        // Query\Builder methods: proxy the call through Builder<Model> to resolve
        // the return type with proper template type preservation.
        $fake_method_call = new MethodCall(
            // Variable name must match what executeFakeCall sets in context: $fakeProxyObject
            new Variable('fakeProxyObject'),
            $methodName,
            $event->getCallArgs(),
        );

        $fakeProxy = new Type\Atomic\TGenericObject($builderClass, [
            new Union([
                new Type\Atomic\TNamedObject($calledClass),
            ]),
        ]);

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

        $called_fq_classlike_name = $event->getCalledFqClasslikeName();

        if (! \is_string($called_fq_classlike_name)) {
            return null;
        }

        // Model::query()
        if ($event->getMethodNameLowercase() === 'query') {
            $builderClass = self::getBuilderClassForModel($called_fq_classlike_name);

            return new Union([
                new Type\Atomic\TGenericObject($builderClass, [
                    new Union([
                        new Type\Atomic\TNamedObject($called_fq_classlike_name),
                    ]),
                ]),
            ]);
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

            $fakeProxy = new Type\Atomic\TGenericObject($builderClass, [
                new Union([
                    new Type\Atomic\TNamedObject($called_fq_classlike_name),
                ]),
            ]);

            return ProxyMethodReturnTypeProvider::executeFakeCall($source, $fake_method_call, $event->getContext(), $fakeProxy);
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
