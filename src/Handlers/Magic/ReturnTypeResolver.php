<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Magic;

use Psalm\Codebase;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Computes return types for forwarded method calls based on ForwardingStyle.
 *
 * This class encapsulates the three return-type strategies used by Laravel's
 * magic method forwarding, translating runtime behavior into static types:
 *
 * - Decorated: check if the target returns itself → return source's type or target's type
 * - AlwaysSelf: unconditionally return source's type
 * - Passthrough: return target's type as-is
 *
 * Caches "does method X return self?" results in a static map, since the answer depends
 * only on the method's declared return type (immutable during a Psalm run).
 */
final class ReturnTypeResolver
{
    /** @var array<string, bool> Cache: "TargetClass::method" → returns self? */
    private static array $selfReturnCache = [];

    /**
     * Reset the self-return cache.
     *
     * Must be called between Psalm runs in long-lived processes (language server,
     * daemon mode) to prevent stale results when stubs or vendor code changes.
     */
    public static function resetCache(): void
    {
        self::$selfReturnCache = [];
    }

    /**
     * Resolve the return type for a forwarded method call.
     *
     * @param ForwardingRule $rule The forwarding rule being applied
     * @param string $sourceClass The FQCN of the class where the call originated (e.g., HasMany)
     * @param list<Union>|null $sourceTemplateParams Template parameters of the source (e.g., [Comment, Post] for HasMany)
     * @param Codebase $codebase Psalm's codebase for method storage lookups
     * @param string $methodNameLowercase The method being called (e.g., 'where')
     * @return Union|null The resolved return type, or null to let Psalm resolve naturally
     */
    public static function resolve(
        ForwardingRule $rule,
        string $sourceClass,
        ?array $sourceTemplateParams,
        Codebase $codebase,
        string $methodNameLowercase,
    ): ?Union {
        if (!self::methodExistsOnSearchClasses($codebase, $rule->searchClasses, $methodNameLowercase)) {
            return null;
        }

        return match ($rule->style) {
            ForwardingStyle::Decorated => self::resolveDecorated(
                $rule,
                $sourceClass,
                $sourceTemplateParams,
                $codebase,
                $methodNameLowercase,
            ),
            ForwardingStyle::AlwaysSelf => self::resolveAlwaysSelf($sourceClass, $sourceTemplateParams),
            ForwardingStyle::Passthrough => self::resolvePassthrough(
                $rule,
                $codebase,
                $methodNameLowercase,
            ),
        };
    }

    /**
     * Decorated style: if the target method returns itself, return the source's type.
     *
     * This mirrors forwardDecoratedCallTo():
     *   $result = $target->method();
     *   return $result === $target ? $this : $result;
     *
     * In type terms: if the target's declared return type contains any of the
     * selfReturnIndicators (e.g., Builder) or is static/$this, we return the source's
     * generic type (e.g., HasMany<Comment, Post>). Otherwise we return null,
     * letting Psalm resolve the target's actual return type via @mixin.
     *
     * @param list<Union>|null $sourceTemplateParams
     */
    private static function resolveDecorated(
        ForwardingRule $rule,
        string $sourceClass,
        ?array $sourceTemplateParams,
        Codebase $codebase,
        string $methodNameLowercase,
    ): ?Union {
        if ($sourceTemplateParams === null || $sourceTemplateParams === []) {
            return null;
        }

        if (self::targetMethodReturnsSelf($codebase, $rule, $methodNameLowercase)) {
            return new Union([
                new TGenericObject($sourceClass, $sourceTemplateParams),
            ]);
        }

        // Non-self-returning methods (e.g., first(), count()): let Psalm resolve naturally.
        return null;
    }

    /**
     * AlwaysSelf style: unconditionally return the source's type.
     *
     * This mirrors Eloquent\Builder::__call():
     *   $this->forwardCallTo($this->query, $method, $parameters);
     *   return $this;  // result discarded
     *
     * @param list<Union>|null $sourceTemplateParams
     * @psalm-pure
     */
    private static function resolveAlwaysSelf(string $sourceClass, ?array $sourceTemplateParams): ?Union
    {
        if ($sourceTemplateParams === null || $sourceTemplateParams === []) {
            return null;
        }

        return new Union([
            new TGenericObject($sourceClass, $sourceTemplateParams),
        ]);
    }

    /**
     * Passthrough style: return the target method's declared return type as-is.
     *
     * This mirrors Model::__call():
     *   return $this->forwardCallTo($this->newQuery(), $method, $parameters);
     *
     * We look up the method's return type from Psalm's storage. Template parameters
     * are NOT resolved here — they depend on how the target was parameterized,
     * which requires the caller to set up a fake call context.
     *
     * For the PoC, this returns the raw declared type. A production version would
     * use ProxyMethodReturnTypeProvider::executeFakeCall() for proper template resolution.
     *
     * @psalm-mutation-free
     */
    private static function resolvePassthrough(
        ForwardingRule $rule,
        Codebase $codebase,
        string $methodNameLowercase,
    ): ?Union {
        foreach ($rule->searchClasses as $targetClass) {
            $returnType = self::getDeclaredReturnType($codebase, $targetClass, $methodNameLowercase);
            if ($returnType instanceof \Psalm\Type\Union) {
                return $returnType;
            }
        }

        return null;
    }

    /**
     * Check if a target method's return type indicates it returns itself.
     *
     * Looks at the declared return type and checks if any atomic type matches
     * the rule's selfReturnIndicators or has is_static=true ($this/static).
     *
     * Results are cached because this is called per method call during analysis,
     * but the answer only depends on the method declaration (static during a run).
     *
     */
    private static function targetMethodReturnsSelf(
        Codebase $codebase,
        ForwardingRule $rule,
        string $methodNameLowercase,
    ): bool {
        $cacheKey = \implode('|', $rule->searchClasses) . '::' . $methodNameLowercase
            . '::' . \implode('|', $rule->selfReturnIndicators);

        if (\array_key_exists($cacheKey, self::$selfReturnCache)) {
            return self::$selfReturnCache[$cacheKey];
        }

        $result = self::computeTargetMethodReturnsSelf($codebase, $rule, $methodNameLowercase);

        return self::$selfReturnCache[$cacheKey] = $result;
    }

    /** @psalm-mutation-free */
    private static function computeTargetMethodReturnsSelf(
        Codebase $codebase,
        ForwardingRule $rule,
        string $methodNameLowercase,
    ): bool {
        $indicatorsLower = \array_map('\strtolower', $rule->selfReturnIndicators);

        foreach ($rule->searchClasses as $targetClass) {
            $returnType = self::getDeclaredReturnType($codebase, $targetClass, $methodNameLowercase);
            if (!$returnType instanceof \Psalm\Type\Union) {
                continue;
            }

            foreach ($returnType->getAtomicTypes() as $atomicType) {
                if ($atomicType instanceof TNamedObject) {
                    // @return static / @return $this: Psalm stores these with is_static=true
                    // and $value set to the declaring class (not the literal 'static').
                    if ($atomicType->is_static) {
                        return true;
                    }

                    $fqcn = \strtolower($atomicType->value);
                    if (\in_array($fqcn, $indicatorsLower, true)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check if a method exists on any of the search classes.
     *
     * Looks up declaring_method_ids in ClassLikeStorage directly (not via
     * Codebase::methodExists) to avoid resolving through __call, which
     * would return __call's storage instead of the actual method.
     *
     * @param list<string> $searchClasses
     * @psalm-mutation-free
     */
    private static function methodExistsOnSearchClasses(
        Codebase $codebase,
        array $searchClasses,
        string $methodNameLowercase,
    ): bool {
        /** @var lowercase-string $methodNameLowercase */
        foreach ($searchClasses as $className) {
            try {
                $classStorage = $codebase->classlike_storage_provider->get(\strtolower($className));
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
     * Get the declared return type for a method from Psalm's storage.
     *
     * Looks up the method via declaring_method_ids to get the real declaration,
     * not the __call fallback.
     *
     * @psalm-mutation-free
     */
    private static function getDeclaredReturnType(
        Codebase $codebase,
        string $className,
        string $methodNameLowercase,
    ): ?Union {
        /** @var lowercase-string $methodNameLowercase */
        try {
            $classStorage = $codebase->classlike_storage_provider->get(\strtolower($className));
        } catch (\InvalidArgumentException) {
            return null;
        }

        $declaringId = $classStorage->declaring_method_ids[$methodNameLowercase] ?? null;
        if ($declaringId === null) {
            return null;
        }

        try {
            $storage = $codebase->methods->getStorage($declaringId);
        } catch (\UnexpectedValueException) {
            return null;
        }

        return $storage->return_type;
    }
}
