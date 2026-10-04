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
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TTemplateParam;
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
     * A builder is shared by a model and every descendant that inherits its
     * `newEloquentBuilder()` / `$builder` (issue #1620), so the mapping is 1:N. Ancestors
     * come first so that, when the receiver's model cannot be read (non-generic builder,
     * unresolved template), the fallback is the least-derived model declaring the method.
     *
     * Existence, visibility and params providers receive no template parameters, so they
     * answer for ANY registered model: `Base::query()->childOnlyScope()` is an accepted
     * false negative. Return-type providers pick the model from the receiver's TModel.
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
     * Ancestors must precede descendants whatever the registration order: the no-receiver
     * fallback picks the least-derived model declaring the method.
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

        $position = \count($models);
        foreach ($models as $index => $registered) {
            if (\is_subclass_of($registered, $modelClass)) {
                $position = $index;
                break;
            }
        }

        \array_splice($models, $position, 0, [$modelClass]);
        self::$builderToModelMap[$builderClass] = $models;
    }

    /**
     * Psalm passes no template params to return providers for magic (`__call`) calls such as scopes,
     * so the receiver expression's type is the fallback, as in {@see BuilderAggregateHandler}.
     *
     * A receiver whose model argument is a bare template parameter (`@template T of Child`,
     * `Builder<T>`) resolves through the template's `as` bound when that is a single model class;
     * `$modelType` then carries the original template union so the returned builder stays generic in it.
     *
     * @return array{modelClass: class-string<Model>|null, modelType: Union|null}
     */
    private static function receiverModel(MethodReturnTypeProviderEvent $event, \Psalm\Codebase $codebase): array
    {
        $stmt = $event->getStmt();
        $lhsType = $stmt instanceof MethodCall
            ? $event->getSource()->getNodeTypeProvider()->getType($stmt->var)
            : null;
        $templateParams = $event->getTemplateTypeParameters();

        $templateType = self::templateParamModelType($templateParams[0] ?? null);
        if (!$templateType instanceof Union && $lhsType instanceof Union) {
            foreach ($lhsType->getAtomicTypes() as $atomic) {
                if ($atomic instanceof TGenericObject) {
                    $templateType = self::templateParamModelType($atomic->type_params[0] ?? null);
                    break;
                }
            }
        }

        if ($templateType instanceof Union) {
            /** @var TTemplateParam $templateParam single atomic, checked by templateParamModelType() */
            $templateParam = $templateType->getSingleAtomic();
            $bound = ModelPropertyResolver::extractExactlyOneModelFromUnion($templateParam->as);
            if ($bound !== null) {
                return ['modelClass' => $bound, 'modelType' => $templateType];
            }
        }

        return [
            'modelClass' => ModelPropertyResolver::resolveExactlyOneModelClass(
                $templateParams,
                0,
                $lhsType,
                $codebase,
            ),
            'modelType' => null,
        ];
    }

    /**
     * @psalm-mutation-free
     */
    private static function templateParamModelType(?Union $type): ?Union
    {
        return $type instanceof Union && $type->isSingle() && $type->getSingleAtomic() instanceof TTemplateParam
            ? $type
            : null;
    }

    /**
     * Builder type for the picked model: the receiver's own template union when it was template-typed,
     * otherwise the concrete `Builder<Model>`.
     *
     * @param class-string<Builder> $builderClass
     * @param class-string<Model> $modelClass
     * @psalm-mutation-free
     */
    private static function returnBuilderType(
        string $builderClass,
        string $modelClass,
        ?Union $receiverModelType,
        \Psalm\Codebase $codebase,
    ): Union {
        return new Union([
            $receiverModelType instanceof Union
                ? ModelMethodHandler::builderTypeWithModelType($builderClass, $receiverModelType, $codebase)
                : ModelMethodHandler::builderType($builderClass, $modelClass, $codebase),
        ]);
    }

    /**
     * A resolved receiver model is authoritative: Laravel's `Builder::__call` checks scopes and
     * macros on the actual model, so when that model does not declare the method the answer is
     * null (decline, let Psalm infer natively) rather than another registered model's. Only an
     * unresolved receiver (non-generic builder, unresolved template) falls back to the first
     * model in ancestor-first order that declares the method.
     *
     * @param class-string<Builder> $builderClass
     * @param class-string<Model>|null $receiverModel
     * @param callable(class-string<Model>): bool $declares
     * @return class-string<Model>|null
     */
    private static function pickModel(string $builderClass, ?string $receiverModel, callable $declares): ?string
    {
        if ($receiverModel !== null) {
            return $declares($receiverModel) ? $receiverModel : null;
        }

        foreach (self::$builderToModelMap[$builderClass] ?? [] as $model) {
            if ($declares($model)) {
                return $model;
            }
        }

        return null;
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
     * Declines while a scope hand-off is pending for the method: the scope return provider resolved a
     * receiver model whose own scope shadows the trait method (a model without the trait), and the scope
     * params provider is the one that consumes the entry, so it must answer rather than this registry-wide
     * lookup. Answering here would leave the entry behind for an unrelated later call.
     *
     * @return list<FunctionLikeParameter>|null
     */
    public static function getTraitMethodParamsOnBuilder(MethodParamsProviderEvent $event): ?array
    {
        /** @var class-string<Builder> $builderClass */
        $builderClass = $event->getFqClasslikeName();

        /** @var lowercase-string $methodName */
        $methodName = $event->getMethodNameLowercase();

        if (BuilderScopeHandler::hasPendingScopeModel($methodName)) {
            return null;
        }

        $modelClass = self::pickModel($builderClass, null, static fn(string $model): bool => self::hasTraitMethod($model, $methodName));

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
        $receiver = self::receiverModel($event, $source->getCodebase());
        $modelClass = self::pickModel(
            $builderClass,
            $receiver['modelClass'],
            static fn(string $model): bool => self::hasTraitMethod($model, $methodName),
        );
        if ($modelClass === null) {
            return null;
        }

        return self::returnBuilderType($builderClass, $modelClass, $receiver['modelType'], $source->getCodebase());
    }

    /**
     * Check if a trait-declared builder method exists for the given custom builder class.
     */
    private static function hasTraitMethodOnBuilder(string $builderClass, string $methodName): bool
    {
        /** @var class-string<Builder> $builderClass */
        return self::pickModel($builderClass, null, static fn(string $model): bool => self::hasTraitMethod($model, $methodName)) !== null;
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
     * Known limitation: when the builder itself declares the method (real or stub, e.g. `count`) and
     * only some of its models declare a like-named scope, params are validated against the builder's
     * declaration. Psalm asks for params before any receiver-aware provider runs, so there is no
     * receiver to pick the right model's scope. Laravel does run such a scope on a model that declares it.
     * See InheritedGenericBuilderScopeCollisionKnownLimitation.phpt.
     *
     * @return list<FunctionLikeParameter>|null
     */
    public static function getScopeMethodParamsOnBuilder(MethodParamsProviderEvent $event): ?array
    {
        /** @var lowercase-string $methodName */
        $methodName = $event->getMethodNameLowercase();

        // Consume first: whatever this provider answers, the hand-off must not outlive the call.
        $modelClass = BuilderScopeHandler::consumePendingScopeModel($methodName);

        $source = $event->getStatementsSource();
        if (!$source instanceof StatementsSource) {
            return null;
        }

        /** @var class-string<Builder> $builderClass */
        $builderClass = $event->getFqClasslikeName();
        $codebase = $source->getCodebase();

        // Existence/params providers see no receiver; the model the return provider resolved
        // rides along via the consume-once hand-off, with the registry fallback only when absent.
        // The fallback declines for methods the builder declares (e.g. the stub's `count`): there
        // is no receiver to pick the right model's scope, so Psalm validates against the declaration.
        if ($modelClass === null || !BuilderScopeHandler::hasScopeMethod($codebase, $modelClass, $methodName)) {
            if (BuilderScopeHandler::builderDeclaresMethod($codebase, $builderClass, $methodName)) {
                return null;
            }

            $modelClass = self::pickModel(
                $builderClass,
                null,
                static fn(string $model): bool => BuilderScopeHandler::hasScopeMethod($codebase, $model, $methodName),
            );
        }

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
        $receiver = self::receiverModel($event, $codebase);
        $modelClass = self::pickModel(
            $builderClass,
            $receiver['modelClass'],
            static fn(string $model): bool => BuilderScopeHandler::hasScopeMethod($codebase, $model, $methodName),
        );
        if ($modelClass === null) {
            return null;
        }

        BuilderScopeHandler::handOffScopeModel($codebase, $builderClass, $methodName, $modelClass);

        // A value-returning scope surfaces its declared return via Laravel's `?? $this` coalesce;
        // a plain void/fluent scope keeps the custom builder type, CustomBuilder<Model> (issue #1053).
        $scopeFallback = self::returnBuilderType($builderClass, $modelClass, $receiver['modelType'], $codebase);

        return BuilderScopeHandler::forwardedScopeReturnType(
            $codebase,
            $modelClass,
            $event->getMethodNameLowercase(),
            $scopeFallback,
        );
    }

    /**
     * Existence providers receive no receiver, so any registered model declaring the scope suffices.
     */
    private static function hasScopeOnBuilder(\Psalm\Codebase $codebase, string $builderClass, string $methodName): bool
    {
        /** @var class-string<Builder> $builderClass */
        return self::pickModel(
            $builderClass,
            null,
            static fn(string $model): bool => BuilderScopeHandler::hasScopeMethod($codebase, $model, $methodName),
        ) !== null;
    }
}
