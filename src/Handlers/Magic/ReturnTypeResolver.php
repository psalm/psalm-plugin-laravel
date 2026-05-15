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
 *
 * @psalm-external-mutation-free
 */
final class ReturnTypeResolver
{
    /**
     * Cache: method name -> returns self?
     *
     * Keyed by method name alone since the active ForwardingRule is a singleton
     * (one rule per Psalm run). Must be cleared via initForRule() when the rule changes
     * (e.g., in tests with multiple init() calls).
     *
     * @var array<string, bool>
     */
    private static array $selfReturnCache = [];

    /**
     * Cache: "target class::method name" -> returns self?
     *
     * @var array<string, bool>
     */
    private static array $targetSelfReturnCache = [];

    private static ?ForwardingRule $rule = null;

    /** @var array<lowercase-string, true> Pre-lowered selfReturnIndicators as a lookup set, set via initForRule() */
    private static array $indicatorsLower = [];

    /**
     * Reset cache and store the active rule with pre-computed lowered indicators.
     *
     * Must be called when the active ForwardingRule changes (e.g., in tests
     * with multiple init() calls). Called from MethodForwardingHandler::init().
     *
     * @psalm-external-mutation-free
     */
    public static function initForRule(ForwardingRule $rule): void
    {
        self::$selfReturnCache = [];
        self::$targetSelfReturnCache = [];
        self::$rule = $rule;
        self::$indicatorsLower = \array_fill_keys(
            \array_map(static fn(string $s): string => \strtolower($s), $rule->selfReturnIndicators),
            true,
        );
    }

    /**
     * Resolve the return type for a forwarded method call (Decorated style).
     *
     * If the target method returns self (Builder, static, $this), returns
     * the source's generic type. Otherwise returns null to let Psalm resolve.
     *
     * @param list<Union>|null $sourceTemplateParams
     * @psalm-external-mutation-free
     */
    public static function resolve(
        string   $sourceClass,
        ?array   $sourceTemplateParams,
        Codebase $codebase,
        string   $methodNameLowercase,
    ): ?Union {
        if (!self::$rule instanceof \Psalm\LaravelPlugin\Handlers\Magic\ForwardingRule || $sourceTemplateParams === null || $sourceTemplateParams === []) {
            return null;
        }

        if (self::anyTargetClassMethodReturnsSelf($codebase, $methodNameLowercase)) {
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
     *
     * @psalm-external-mutation-free
     */
    private static function anyTargetClassMethodReturnsSelf(
        Codebase $codebase,
        string $methodNameLowercase,
    ): bool {
        // Cache key is just the method name — the rule is a singleton per Psalm run.
        if (\array_key_exists($methodNameLowercase, self::$selfReturnCache)) {
            return self::$selfReturnCache[$methodNameLowercase];
        }

        $rule = self::$rule;
        \assert($rule instanceof \Psalm\LaravelPlugin\Handlers\Magic\ForwardingRule, 'initForRule() must be called before anyTargetClassMethodReturnsSelf()');

        $result = false;

        foreach ($rule->searchClasses as $searchClass) {
            $returnType = self::getDeclaredReturnType($codebase, $searchClass, $methodNameLowercase);

            if ($returnType instanceof Union && self::returnTypeIndicatesSelf($returnType)) {
                $result = true;
                break;
            }
        }

        self::$selfReturnCache[$methodNameLowercase] = $result;

        return $result;
    }

    /**
     * Check one target class instead of all search classes in the active forwarding rule.
     *
     * @psalm-external-mutation-free
     */
    public static function targetClassMethodReturnsSelf(
        Codebase $codebase,
        string $targetClass,
        string $methodNameLowercase,
    ): bool {
        $key = \strtolower($targetClass) . '::' . $methodNameLowercase;

        if (\array_key_exists($key, self::$targetSelfReturnCache)) {
            return self::$targetSelfReturnCache[$key];
        }

        $returnType = self::getDeclaredReturnType($codebase, $targetClass, $methodNameLowercase);

        if (!$returnType instanceof Union) {
            return self::$targetSelfReturnCache[$key] = false;
        }

        return self::$targetSelfReturnCache[$key] = self::returnTypeIndicatesSelf($returnType);
    }

    /**
     * @psalm-external-mutation-free
     */
    private static function returnTypeIndicatesSelf(Union $returnType): bool
    {
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
            if (isset(self::$indicatorsLower[\strtolower($atomicType->value)])) {
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
