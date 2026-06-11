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
use Psalm\Internal\MethodIdentifier;
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
        // declared on abstract parent models or in traits keep their signatures.
        return self::$scopeParamsCache[$key] = \array_slice(
            $codebase->methods->getMethodParams($scopeMethodId),
            1,
        );
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

        foreach ($methodStorage->attributes as $attribute) {
            if ($attribute->fq_class_name === Scope::class) {
                return true;
            }
        }

        return false;
    }
}
