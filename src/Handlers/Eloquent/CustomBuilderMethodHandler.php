<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\Event\MethodExistenceProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodVisibilityProviderEvent;
use Psalm\StatementsSource;
use Psalm\Storage\FunctionLikeParameter;
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
     * Reverse map: custom builder FQCN → model FQCN.
     *
     * Used to look up the model for a custom builder class.
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
     * Register the builder-to-model reverse mapping.
     *
     * Called by {@see ModelMethodHandler::registerCustomBuilder} when a model declares
     * a custom builder.
     *
     * @param class-string<Model> $modelClass
     * @param class-string<Builder> $builderClass
     * @psalm-external-mutation-free
     */
    public static function registerBuilderToModelMapping(string $modelClass, string $builderClass): void
    {
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
     * Check if a trait-declared builder method exists for the given model.
     *
     * Used by {@see ModelMethodHandler} to check trait method existence in the
     * model-level handlers (isUnresolvedBuilderMethod, getMethodParams, etc.).
     *
     * @psalm-external-mutation-free
     */
    public static function hasTraitMethod(string $modelClass, string $methodName): bool
    {
        return isset(self::$traitBuilderMethods[$modelClass][$methodName]);
    }

    /**
     * Get params for a trait-declared builder method on a model.
     *
     * @return list<FunctionLikeParameter>|null
     * @psalm-external-mutation-free
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

        return new Union([ModelMethodHandler::builderType($builderClass, $modelClass, $source->getCodebase())]);
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

        $codebase = $source->getCodebase();
        $methodName = $event->getMethodNameLowercase();

        // Guard required: getScopeParams matches any model method in its #[Scope] branch
        // (it checks methodExists but not the attribute). Without this, non-scope model methods
        // like __construct would be matched, causing TooManyArguments on custom builder constructors.
        if (!BuilderScopeHandler::hasScopeMethod($codebase, $modelClass, $methodName)) {
            return null;
        }

        return ModelMethodHandler::getScopeParams($codebase, $modelClass, $methodName);
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

        return new Union([ModelMethodHandler::builderType($builderClass, $modelClass, $codebase)]);
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
        $modelClass = self::$builderToModelMap[$builderClass] ?? null;
        if ($modelClass === null) {
            return false;
        }

        /** @var class-string<Model> $modelClass */
        return BuilderScopeHandler::hasScopeMethod($codebase, $modelClass, $methodName);
    }
}
