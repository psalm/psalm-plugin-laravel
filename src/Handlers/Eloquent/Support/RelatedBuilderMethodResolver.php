<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PhpParser\Node\Arg;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Internal\Analyzer\Statements\Expression\Call\ClassTemplateParamCollector;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\Type\TemplateBound;
use Psalm\Internal\Type\TemplateInferredTypeReplacer;
use Psalm\Internal\Type\TemplateResult;
use Psalm\Internal\Type\TypeExpander;
use Psalm\LaravelPlugin\Handlers\Eloquent\BuilderScopeHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\CustomBuilderMethodHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelMethodHandler;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Storage\MethodStorage;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Resolves methods that Laravel forwards from a Relation to its related model's builder.
 *
 * This deliberately works from Psalm storage only. Instantiating a model/builder would boot
 * application code, while analyzing a synthetic call recursively has previously caused
 * unbounded memory usage in the relation forwarding provider.
 *
 * @internal
 */
final class RelatedBuilderMethodResolver
{
    /**
     * Declared-method lookup cache. Signatures no longer depend on per-call argument types;
     * localization depends only on the effective builder's class templates.
     *
     * @var array<string, array{MethodIdentifier, MethodStorage}|null>
     */
    private static array $declaredMethodCache = [];

    /**
     * Class-level template localization is stable for an effective builder atomic.
     *
     * @var array<string, array{
     *     ClassLikeStorage,
     *     MethodIdentifier,
     *     ClassLikeStorage,
     *     array<string, non-empty-array<string, Union>>|null
     * }|null>
     */
    private static array $specializationContextCache = [];

    /** @psalm-external-mutation-free */
    public static function reset(): void
    {
        self::$declaredMethodCache = [];
        self::$specializationContextCache = [];
    }

    /**
     * Resolve a real public instance method introduced by a custom builder hierarchy.
     * Methods merely inherited from Eloquent's base Builder are intentionally excluded:
     * MethodForwardingHandler's existing Builder/Query Builder path owns those methods.
     *
     * @param class-string<Model> $modelClass
     * @param lowercase-string $methodName
     * @param list<Arg> $arguments
     */
    public static function resolveDeclaredMethod(
        Codebase $codebase,
        StatementsAnalyzer $source,
        string $modelClass,
        string $methodName,
        array $arguments,
    ): ?ResolvedForwardedMethod {
        $builderType = ModelMethodHandler::resolvedBuilderTypeFor($modelClass, $codebase);
        /** @var class-string<Builder> $builderClass */
        $builderClass = $builderType->value;

        if ($builderClass === Builder::class) {
            return null;
        }

        $resolvedStorage = self::declaredMethodStorage($codebase, $builderClass, $methodName);
        if ($resolvedStorage === null) {
            return null;
        }

        [$methodId, $methodStorage] = $resolvedStorage;

        $specializationContext = self::specializationContext(
            $codebase,
            $builderClass,
            $builderType,
            $methodId,
            $methodName,
        );
        if ($specializationContext === null) {
            return new ResolvedForwardedMethod(
                $methodStorage->return_type ?? $methodStorage->signature_return_type ?? Type::getMixed(),
                DynamicWhereResolver::variadicMixedParams(),
            );
        }

        [$builderStorage, $declaringMethodId, $declaringClassStorage, $classTemplateParams]
            = $specializationContext;

        $templateResult = new TemplateResult([], $classTemplateParams ?? []);
        if ($methodStorage->template_types !== null) {
            $templateResult->template_types += $methodStorage->template_types;
        }

        self::boundMethodTemplates($methodStorage, $templateResult);

        $methodReturnSelfClass = $declaringMethodId->fq_class_name;

        try {
            // Psalm 6's getMethodReturnType() has no leading Codebase param and takes
            // $self_class by reference (Psalm 7 added the Codebase param and made it a value param).
            $returnType = $codebase->methods->getMethodReturnType(
                $methodId,
                $methodReturnSelfClass,
                $source,
                $arguments,
                $templateResult,
            );
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            $returnType = null;
        }

        // The declaration owns `self`; the effective builder atomic passed separately
        // owns late-static (`static`/`$this`) expansion.
        $selfClass = $declaringMethodId->fq_class_name;

        $returnType ??= $methodStorage->return_type
            ?? $methodStorage->signature_return_type
            ?? Type::getMixed();

        $returnType = self::specializeUnion(
            $codebase,
            $returnType,
            $templateResult,
            $selfClass,
            $builderType,
            $declaringClassStorage->parent_class,
            $builderStorage->final,
        );

        $parameters = [];
        foreach ($methodStorage->params as $parameter) {
            $parameters[] = self::specializeParameter(
                $codebase,
                $parameter,
                $templateResult,
                $selfClass,
                $builderType,
                $declaringClassStorage->parent_class,
                $builderStorage->final,
            );
        }

        if ($methodStorage->variadic && !self::hasVariadicParameter($parameters)) {
            $parameters[] = new FunctionLikeParameter(
                name: 'args',
                by_ref: false,
                type: Type::getMixed(),
                is_variadic: true,
            );
        }

        return new ResolvedForwardedMethod($returnType, $parameters);
    }

    /**
     * @param class-string<Builder> $builderClass
     * @param lowercase-string $methodName
     * @return array{
     *     ClassLikeStorage,
     *     MethodIdentifier,
     *     ClassLikeStorage,
     *     array<string, non-empty-array<string, Union>>|null
     * }|null
     */
    private static function specializationContext(
        Codebase $codebase,
        string $builderClass,
        TNamedObject $builderType,
        MethodIdentifier $methodId,
        string $methodName,
    ): ?array {
        $cacheKey = \strtolower($builderType->getId()) . '::' . $methodName;
        if (\array_key_exists($cacheKey, self::$specializationContextCache)) {
            return self::$specializationContextCache[$cacheKey];
        }

        try {
            $builderStorage = $codebase->classlike_storage_provider->get(\strtolower($builderClass));
            $methodClassStorage = $codebase->methods->getClassLikeStorageForMethod($methodId);
            $declaringMethodId = $codebase->methods->getDeclaringMethodId($methodId) ?? $methodId;
            $declaringClassStorage = $codebase->classlike_storage_provider->get(
                \strtolower($declaringMethodId->fq_class_name),
            );

            $classTemplateParams = ClassTemplateParamCollector::collect(
                $codebase,
                $methodClassStorage,
                $builderStorage,
                $methodName,
                $builderType,
            );
        } catch (\InvalidArgumentException|\UnexpectedValueException|\AssertionError) {
            return self::$specializationContextCache[$cacheKey] = null;
        }

        return self::$specializationContextCache[$cacheKey] = [
            $builderStorage,
            $declaringMethodId,
            $declaringClassStorage,
            $classTemplateParams,
        ];
    }

    /**
     * Resolve builder-returning pseudo-methods contributed by model traits. Registration
     * already filters this metadata to fluent Builder returns, so no framework method names
     * are hard-coded here.
     *
     * @param class-string<Model> $modelClass
     * @param lowercase-string $methodName
     * @psalm-external-mutation-free
     */
    public static function resolveTraitMethod(
        Codebase $codebase,
        string $modelClass,
        string $methodName,
    ): ?ResolvedForwardedMethod {
        $builderType = ModelMethodHandler::resolvedBuilderTypeFor($modelClass, $codebase);

        if ($builderType->value === Builder::class) {
            $parameters = BuilderScopeHandler::getTraitMethodParamsForModel($codebase, $modelClass, $methodName);
        } else {
            if (!CustomBuilderMethodHandler::hasTraitMethod($modelClass, $methodName)) {
                return null;
            }

            $parameters = CustomBuilderMethodHandler::getTraitMethodParams($modelClass, $methodName);
        }

        if ($parameters === null) {
            return null;
        }

        return new ResolvedForwardedMethod(new Union([$builderType]), $parameters);
    }

    /**
     * @param class-string<Builder> $builderClass
     * @param lowercase-string $methodName
     * @return array{MethodIdentifier, MethodStorage}|null
     * @psalm-external-mutation-free
     */
    private static function declaredMethodStorage(
        Codebase $codebase,
        string $builderClass,
        string $methodName,
    ): ?array {
        $cacheKey = \strtolower($builderClass) . '::' . $methodName;

        if (\array_key_exists($cacheKey, self::$declaredMethodCache)) {
            return self::$declaredMethodCache[$cacheKey];
        }

        try {
            $builderStorage = $codebase->classlike_storage_provider->get(\strtolower($builderClass));
            $baseBuilderStorage = $codebase->classlike_storage_provider->get(\strtolower(Builder::class));
        } catch (\InvalidArgumentException) {
            return self::$declaredMethodCache[$cacheKey] = null;
        }

        $declaringMethodId = $builderStorage->declaring_method_ids[$methodName] ?? null;
        if ($declaringMethodId === null) {
            return self::$declaredMethodCache[$cacheKey] = null;
        }

        // The same declaring id means the custom builder merely inherited Laravel's method.
        // A different id includes methods on shared application builder bases and trait imports.
        $baseDeclaringMethodId = $baseBuilderStorage->declaring_method_ids[$methodName] ?? null;
        if (
            $baseDeclaringMethodId !== null
            && \strtolower((string) $baseDeclaringMethodId) === \strtolower((string) $declaringMethodId)
        ) {
            return self::$declaredMethodCache[$cacheKey] = null;
        }

        try {
            $methodStorage = $codebase->methods->getStorage($declaringMethodId);
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            return self::$declaredMethodCache[$cacheKey] = null;
        }

        if ($methodStorage->is_static || $methodStorage->visibility !== ClassLikeAnalyzer::VISIBILITY_PUBLIC) {
            return self::$declaredMethodCache[$cacheKey] = null;
        }

        return self::$declaredMethodCache[$cacheKey] = [
            new MethodIdentifier($builderClass, $methodName),
            $methodStorage,
        ];
    }

    /**
     * This provider fires on the missing-method path before Psalm's normal argument analyzer
     * runs, so argument types are not reliably available yet (nested calls and unpacked
     * arguments have not been analyzed). Inferring a template from only the arguments that
     * happen to already have a type would be unsound, since a later-analyzed sibling argument
     * could validly widen the same template. Making that inference safe would mean duplicating
     * Psalm's own call analysis inside a relation-specific handler, which has previously caused
     * runaway memory use. Every method template therefore degrades unconditionally and
     * predictably to its declared upper bound (normally `mixed`).
     */
    private static function boundMethodTemplates(
        MethodStorage $methodStorage,
        TemplateResult $templateResult,
    ): void {
        foreach ($methodStorage->template_types ?? [] as $templateName => $definingClasses) {
            foreach ($definingClasses as $definingClass => $bound) {
                $templateResult->lower_bounds[$templateName][$definingClass] = [new TemplateBound($bound)];
            }
        }
    }

    private static function specializeParameter(
        Codebase $codebase,
        FunctionLikeParameter $parameter,
        TemplateResult $templateResult,
        string $selfClass,
        TNamedObject $builderType,
        ?string $parentClass,
        bool $final,
    ): FunctionLikeParameter {
        $parameter = clone $parameter;

        foreach (['type', 'signature_type', 'out_type', 'default_type'] as $property) {
            $type = $parameter->{$property};
            if (!$type instanceof Union) {
                continue;
            }

            $parameter->{$property} = self::specializeUnion(
                $codebase,
                $type,
                $templateResult,
                $selfClass,
                $builderType,
                $parentClass,
                $final,
            );
        }

        return $parameter;
    }

    private static function specializeUnion(
        Codebase $codebase,
        Union $type,
        TemplateResult $templateResult,
        string $selfClass,
        TNamedObject $builderType,
        ?string $parentClass,
        bool $final,
    ): Union {
        $type = TypeExpander::expandUnion(
            $codebase,
            $type,
            $selfClass,
            $builderType,
            $parentClass,
            true,
            false,
            $final,
            true,
        );

        if ($templateResult->lower_bounds !== []) {
            $type = TemplateInferredTypeReplacer::replace($type, $templateResult, $codebase);
        }

        return TypeExpander::expandUnion(
            $codebase,
            $type,
            $selfClass,
            $builderType,
            $parentClass,
            true,
            false,
            $final,
            true,
        );
    }

    /**
     * @param list<FunctionLikeParameter> $parameters
     * @psalm-mutation-free
     */
    private static function hasVariadicParameter(array $parameters): bool
    {
        foreach ($parameters as $parameter) {
            if ($parameter->is_variadic) {
                return true;
            }
        }

        return false;
    }
}
