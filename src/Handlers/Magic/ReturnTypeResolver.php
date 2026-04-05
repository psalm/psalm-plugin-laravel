<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Magic;

use Psalm\Codebase;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Determines whether a forwarded method "returns self" (Decorated style)
 * and constructs the source's generic return type when it does.
 *
 * Results are cached per method+rule combination since return types are
 * immutable during a single Psalm run.
 */
final class ReturnTypeResolver
{
    /**
     * Cache: method name -> returns self?
     *
     * Keyed by method name alone since the active ForwardingRule is a singleton
     * (one rule per Psalm run). Must be cleared via resetCache() when the rule changes
     * (e.g., in tests with multiple init() calls).
     *
     * @var array<string, bool>
     */
    private static array $selfReturnCache = [];

    /** @var list<lowercase-string> Pre-lowered selfReturnIndicators, set via resetCache() */
    private static array $indicatorsLower = [];

    public static function resetCache(): void
    {
        self::$selfReturnCache = [];
        self::$indicatorsLower = [];
    }

    /**
     * Resolve the return type for a forwarded method call (Decorated style).
     *
     * If the target method returns self (Builder, static, $this), returns
     * the source's generic type. Otherwise returns null to let Psalm resolve.
     *
     * @param list<Union>|null $sourceTemplateParams
     */
    public static function resolve(
        ForwardingRule $rule,
        string         $sourceClass,
        ?array         $sourceTemplateParams,
        Codebase       $codebase,
        string         $methodNameLowercase,
    ): ?Union {
        if ($sourceTemplateParams === null || $sourceTemplateParams === []) {
            return null;
        }

        if (!self::methodExistsOnSearchClasses($codebase, $rule->searchClasses, $methodNameLowercase)) {
            return null;
        }

        if (self::targetMethodReturnsSelf($codebase, $rule, $methodNameLowercase)) {
            return new Union([
                new TGenericObject($sourceClass, $sourceTemplateParams),
            ]);
        }

        // Non-self-returning (first, get, count, etc.): let Psalm resolve naturally
        return null;
    }

    /**
     * Check if the target method's return type indicates a self-returning (fluent) method.
     *
     * Three conditions trigger self-return detection (any match returns true):
     * 1. TNamedObject with value="static" — Psalm stores @return $this / @return static
     *    as TNamedObject(value="static", is_static=false). Used by QueryBuilder stubs.
     * 2. TNamedObject with is_static=true — alternative representation for static returns
     *    (safety net, primarily condition 1 fires in practice)
     * 3. TNamedObject matching selfReturnIndicators — catches explicit class returns
     *    like Builder::where() which declares @return self<TModel>
     *    (Psalm stores this as TGenericObject("Builder", [TModel]))
     */
    private static function targetMethodReturnsSelf(
        Codebase $codebase,
        ForwardingRule $rule,
        string $methodNameLowercase,
    ): bool {
        // Cache key is just the method name — the rule is a singleton per Psalm run.
        if (\array_key_exists($methodNameLowercase, self::$selfReturnCache)) {
            return self::$selfReturnCache[$methodNameLowercase];
        }

        $result = self::resolveTargetMethodReturnsSelf($codebase, $rule, $methodNameLowercase);
        self::$selfReturnCache[$methodNameLowercase] = $result;

        return $result;
    }

    /** @psalm-external-mutation-free */
    private static function resolveTargetMethodReturnsSelf(
        Codebase $codebase,
        ForwardingRule $rule,
        string $methodNameLowercase,
    ): bool {
        // Lazy-init: compute lowered indicators once per rule (cleared via resetCache())
        if (self::$indicatorsLower === [] && $rule->selfReturnIndicators !== []) {
            /** @var list<lowercase-string> */
            self::$indicatorsLower = \array_map(static fn(string $s): string => \strtolower($s), $rule->selfReturnIndicators);
        }

        $indicatorsLower = self::$indicatorsLower;

        foreach ($rule->searchClasses as $searchClass) {
            $returnType = self::getDeclaredReturnType($codebase, $searchClass, $methodNameLowercase);

            if ($returnType === null) {
                continue;
            }

            foreach ($returnType->getAtomicTypes() as $atomicType) {
                if (!$atomicType instanceof TNamedObject) {
                    continue;
                }

                // Check 1: @return $this / @return static
                // Psalm stores these as TNamedObject(value="static", is_static=false),
                // NOT as is_static=true. Match the literal "static" value.
                if ($atomicType->value === 'static' || $atomicType->is_static) {
                    return true;
                }

                // Check 2: class name matches selfReturnIndicators (e.g., Builder)
                if (\in_array(\strtolower($atomicType->value), $indicatorsLower, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if the method exists on any of the search classes via declaring_method_ids.
     *
     * Uses ClassLikeStorage->declaring_method_ids instead of Codebase->methodExists()
     * to avoid resolving through __call (which would give mixed return type).
     *
     * @param list<string> $searchClasses
     * @psalm-mutation-free
     */
    private static function methodExistsOnSearchClasses(
        Codebase $codebase,
        array $searchClasses,
        string $methodNameLowercase,
    ): bool {
        foreach ($searchClasses as $searchClass) {
            try {
                $classStorage = $codebase->classlike_storage_provider->get(\strtolower($searchClass));
            } catch (\InvalidArgumentException) {
                continue;
            }

            if (isset($classStorage->declaring_method_ids[$methodNameLowercase])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the declared return type for a method from ClassLikeStorage.
     *
     * All lookups go through classlike_storage_provider->get() and declaring_method_ids
     * to get the real MethodStorage, NOT through methodExists() which could resolve
     * through __call and return mixed.
     *
     * @psalm-mutation-free
     */
    private static function getDeclaredReturnType(
        Codebase $codebase,
        string $class,
        string $methodNameLowercase,
    ): ?Union {
        try {
            $classStorage = $codebase->classlike_storage_provider->get(\strtolower($class));
        } catch (\InvalidArgumentException) {
            return null;
        }

        $declaringId = $classStorage->declaring_method_ids[$methodNameLowercase] ?? null;

        if ($declaringId === null) {
            return null;
        }

        try {
            $methodStorage = $codebase->methods->getStorage($declaringId);
        } catch (\UnexpectedValueException) {
            return null;
        }

        return $methodStorage->return_type;
    }
}
