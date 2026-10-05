<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PhpParser\Node\Expr\MethodCall;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\ModelPropertyResolver;
use Psalm\Plugin\EventHandler\Event\MethodExistenceProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodVisibilityProviderEvent;
use Psalm\StatementsSource;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Union;

/**
 * Handles method calls on custom Eloquent Builder instances for trait-declared
 * methods and scope methods.
 *
 * When a model has a custom builder (e.g., PostBuilder), builder instance calls like
 * `Post::query()->withTrashed()` or `Post::query()->featured()` trigger lookups on
 * PostBuilder. This handler confirms existence, visibility, params, and return types
 * for these methods.
 *
 * Trait-declared methods (e.g., SoftDeletes::withTrashed) are macro-registered on the
 * builder at runtime via global scopes. Scope methods (legacy scopeXxx or #[Scope])
 * are forwarded via Builder::__call.
 *
 * Registered per custom builder class by {@see ModelRegistrationHandler}.
 *
 * @see ModelMethodHandler for the model-level static call handlers
 */
final class CustomBuilderMethodHandler
{
    /**
     * Reverse map: custom builder FQCN → models using it, ancestor-first.
     *
     * A builder is shared by a model and every descendant that inherits its `newEloquentBuilder()` /
     * `$builder` (issue #1620). Keying one builder to one model (last registration wins) made
     * `Base::query()->withTrashed()` resolve to a descendant, depending on registration order.
     * Return types now come from the receiver's model; existence, visibility and params providers see
     * no receiver, so they consult the list. Accepted compromises:
     *  - A descendant override with extra parameters is validated against the ancestor's signature
     *    (`Child::query()->visible(true)` reports TooManyArguments).
     *  - Sibling-only scopes (no common ancestor declaring them) are order-dependent.
     *  - A template-typed receiver (`Builder<T>`) falls back to the least-derived declaring model.
     *  - Existence is true if any model declares the method, so a receiver whose model cannot be
     *    resolved (e.g. template-typed) is not rejected for a method only a descendant declares.
     *    A resolved `Base::query()->childOnlyScope()` is rejected: the return providers decline and
     *    Psalm reports the magic call.
     *
     * @var array<class-string<Builder>, list<class-string<Model>>>
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

    public static function reset(): void
    {
        self::$builderToModelMap = [];
        self::$traitBuilderMethods = [];
    }

    /**
     * Register the builder-to-model reverse mapping.
     *
     * Called by {@see ModelMethodHandler::registerCustomBuilder} when a model declares
     * a custom builder.
     *
     * @param class-string<Model> $modelClass
     * @param class-string<Builder> $builderClass
     */
    public static function registerBuilderToModelMapping(string $modelClass, string $builderClass): void
    {
        $models = self::$builderToModelMap[$builderClass] ?? [];
        if (\in_array($modelClass, $models, true)) {
            return;
        }

        // Ancestor-first: an ancestor registered after its descendant goes to the front.
        if ($models !== [] && \is_subclass_of($models[0], $modelClass)) {
            \array_unshift($models, $modelClass);
        } else {
            $models[] = $modelClass;
        }

        self::$builderToModelMap[$builderClass] = $models;
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
     */
    public static function registerTraitBuilderMethods(string $modelClass, array $methods): void
    {
        self::$traitBuilderMethods[$modelClass] = $methods;
    }

    /**
     * Check if a trait-declared builder method exists for the given model.
     *
     * Used by {@see ModelMethodHandler} to check trait method existence in the
     * model-level handlers (isUnresolvedBuilderMethod, getMethodParams, etc.).
     */
    public static function hasTraitMethod(string $modelClass, string $methodName): bool
    {
        return isset(self::$traitBuilderMethods[$modelClass][$methodName]);
    }

    /**
     * Get params for a trait-declared builder method on a model.
     *
     * @return list<FunctionLikeParameter>|null
     */
    public static function getTraitMethodParams(string $modelClass, string $methodName): ?array
    {
        return self::$traitBuilderMethods[$modelClass][$methodName] ?? null;
    }

    // -----------------------------------------------------------------------
    // Trait method handlers (e.g., SoftDeletes::withTrashed on custom builders)
    // -----------------------------------------------------------------------

    /**
     * Confirm trait-declared builder methods exist on custom builder instances.
     */
    public static function doesTraitMethodExistOnBuilder(MethodExistenceProviderEvent $event): ?bool
    {
        return self::hasTraitMethodOnBuilder($event->getFqClasslikeName(), $event->getMethodNameLowercase())
            ? true
            : null;
    }

    /**
     * Trait-declared builder methods forwarded via macros are effectively public.
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
     */
    public static function getTraitMethodParamsOnBuilder(MethodParamsProviderEvent $event): ?array
    {
        /** @var class-string<Builder> $builderClass */
        $builderClass = $event->getFqClasslikeName();

        /** @var lowercase-string $methodName */
        $methodName = $event->getMethodNameLowercase();
        $modelClass = self::declaringModel($builderClass, static fn(string $model): bool => self::hasTraitMethod($model, $methodName));

        return $modelClass !== null ? self::$traitBuilderMethods[$modelClass][$methodName] ?? null : null;
    }

    /**
     * Provide return type for trait-declared builder methods on custom builder instances.
     */
    public static function getTraitMethodReturnTypeOnBuilder(MethodReturnTypeProviderEvent $event): ?Union
    {
        $source = $event->getSource();
        if (!$source instanceof StatementsAnalyzer) {
            return null;
        }

        /** @var class-string<Builder> $builderClass */
        $builderClass = $event->getFqClasslikeName();
        $methodName = $event->getMethodNameLowercase();
        $modelClass = self::returnModel($event, static fn(string $model): bool => self::hasTraitMethod($model, $methodName));
        if ($modelClass === null) {
            return null;
        }

        return new Union([ModelMethodHandler::builderType($builderClass, $modelClass, $source->getCodebase())]);
    }

    /**
     * Check if a trait-declared builder method exists for the given custom builder class.
     */
    private static function hasTraitMethodOnBuilder(string $builderClass, string $methodName): bool
    {
        /** @var class-string<Builder> $builderClass */
        return self::declaringModel($builderClass, static fn(string $model): bool => self::hasTraitMethod($model, $methodName)) !== null;
    }

    // -----------------------------------------------------------------------
    // Scope method handlers on custom builders.
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

        return self::hasScopeOnBuilder(
            $source->getCodebase(),
            $event->getFqClasslikeName(),
            $event->getMethodNameLowercase(),
        )
            ? true
            : null;
    }

    /**
     * Scope methods on custom builders are effectively public (invoked via __call magic).
     */
    public static function isScopeMethodVisibleOnBuilder(MethodVisibilityProviderEvent $event): ?bool
    {
        return self::hasScopeOnBuilder(
            $event->getSource()->getCodebase(),
            $event->getFqClasslikeName(),
            $event->getMethodNameLowercase(),
        )
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
        $codebase = $source->getCodebase();
        $methodName = $event->getMethodNameLowercase();
        $modelClass = self::declaringModel(
            $builderClass,
            static fn(string $model): bool => BuilderScopeHandler::hasScopeMethod($codebase, $model, $methodName),
        );
        if ($modelClass === null) {
            return null;
        }

        // getScopeParams detection is strict (bare methods require the #[Scope] attribute),
        // so non-scope model methods like __construct return null and custom builder
        // constructors keep their own params.
        return BuilderScopeHandler::getScopeParams($codebase, $modelClass, $methodName);
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
        $codebase = $source->getCodebase();
        $methodName = $event->getMethodNameLowercase();
        $modelClass = self::returnModel(
            $event,
            static fn(string $model): bool => BuilderScopeHandler::hasScopeMethod($codebase, $model, $methodName),
        );
        if ($modelClass === null) {
            return null;
        }

        // A value-returning scope surfaces its declared return via Laravel's `?? $this` coalesce;
        // a plain void/fluent scope keeps the custom builder type, CustomBuilder<Model> (issue #1053).
        $scopeFallback = new Union([ModelMethodHandler::builderType($builderClass, $modelClass, $codebase)]);

        return BuilderScopeHandler::forwardedScopeReturnType(
            $codebase,
            $modelClass,
            $event->getMethodNameLowercase(),
            $scopeFallback,
        );
    }

    /**
     * Check if a scope method exists for the given custom builder class.
     *
     * Looks up the model associated with the builder, then delegates to
     * BuilderScopeHandler for scope detection.
     */
    private static function hasScopeOnBuilder(\Psalm\Codebase $codebase, string $builderClass, string $methodName): bool
    {
        /** @var class-string<Builder> $builderClass */
        return self::declaringModel(
            $builderClass,
            static fn(string $model): bool => BuilderScopeHandler::hasScopeMethod($codebase, $model, $methodName),
        ) !== null;
    }

    /**
     * First model of the builder (ancestor-first) satisfying `$declares`.
     *
     * @param class-string<Builder> $builderClass
     * @param callable(class-string<Model>): bool $declares
     * @return class-string<Model>|null
     */
    private static function declaringModel(string $builderClass, callable $declares): ?string
    {
        foreach (self::$builderToModelMap[$builderClass] ?? [] as $model) {
            if ($declares($model)) {
                return $model;
            }
        }

        return null;
    }

    /**
     * Model for a return type: the receiver's model when known (a resolved receiver lacking the method
     * declines), else the first declaring model. The model comes from the event's template parameter, or
     * for magic (`__call`) calls, which carry none, from the single receiver-type arm of this builder class.
     *
     * @param callable(class-string<Model>): bool $declares
     * @return class-string<Model>|null
     */
    private static function returnModel(MethodReturnTypeProviderEvent $event, callable $declares): ?string
    {
        /** @var class-string<Builder> $builderClass */
        $builderClass = $event->getFqClasslikeName();
        $stmt = $event->getStmt();
        $lhsType = $stmt instanceof MethodCall ? $event->getSource()->getNodeTypeProvider()->getType($stmt->var) : null;
        $arms = \array_filter(
            $lhsType instanceof Union ? $lhsType->getAtomicTypes() : [],
            static fn(Atomic $atomic): bool => $atomic instanceof TGenericObject
                && \strtolower($atomic->value) === \strtolower($builderClass),
        );
        $arm = \count($arms) === 1 ? \reset($arms) : null;
        $codebase = $event->getSource()->getCodebase();
        $receiver = ModelPropertyResolver::extractExactlyOneModelFromUnion($event->getTemplateTypeParameters()[0] ?? null, $codebase)
            ?? ModelPropertyResolver::extractExactlyOneModelFromUnion($arm instanceof TGenericObject ? $arm->type_params[0] ?? null : null, $codebase);

        if ($receiver !== null) {
            return $declares($receiver) ? $receiver : null;
        }

        return self::declaringModel($builderClass, $declares);
    }
}
