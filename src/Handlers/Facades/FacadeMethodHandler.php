<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Facades;

use Illuminate\Support\Facades\Facade;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\Plugin\EventHandler\Event\MethodExistenceProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\StatementsSource;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Storage\MethodStorage;
use Psalm\Type;
use Psalm\Type\Union;

/**
 * Resolves `FacadeClass::method()` calls for app-owned Facade subclasses that do
 * not enumerate every forwarded method in `@method`.
 *
 * Resolution order (what we delegate vs. what we enforce):
 * - Real methods — handled natively by Psalm (`naive_method_exists` in
 *   AtomicStaticCallAnalyzer runs before our existence_provider).
 * - `@mixin` — handled natively too (AtomicStaticCallAnalyzer mixin walk); we do
 *   NOT parse `@mixin` ourselves to avoid racing Psalm's own walk.
 * - `@method` — NOT part of `naive_method_exists` (it runs with `with_pseudo=false`).
 *   Our return_type_provider would otherwise fire BEFORE `checkPseudoMethod`, so
 *   {@see resolveMethod()} short-circuits when the facade (or any ancestor) declares
 *   a matching `@method` tag.
 * - Container-resolved root class — {@see AppFacadeRegistrationHandler} calls
 *   `Facade::getFacadeRoot()` while the Testbench app is known alive, binds the
 *   resolved class into per-facade closures, and registers them with Psalm. Methods
 *   present on that class are forwarded to the facade.
 *
 * @internal
 */
final class FacadeMethodHandler
{
    /**
     * @var array<string, ?MethodStorage> "facade::method_lower" => resolved underlying method, or null
     */
    private static array $methodCache = [];

    /**
     * @var array<string, array<lowercase-string, MethodStorage>> facadeClass => pseudo_static_methods union from self + ancestors
     *
     * Cached per facade so the `hasPseudoStaticMethod` gate is O(1) amortised instead of
     * a per-call ancestor-chain walk.
     */
    private static array $pseudoMethodCache = [];

    /**
     * Resolve the facade's container-bound root object and return its class.
     *
     * Called by {@see AppFacadeRegistrationHandler} once per facade — during scan to
     * queue the result for scanning, and during populate to register method providers.
     * Laravel's `Facade` caches the resolved instance in `static::$resolvedInstance`,
     * so the second call is free.
     *
     * Works when:
     * - The accessor is a class-string (e.g. `protected static function getFacadeAccessor()
     *   { return MyService::class; }`) — the container auto-wires it via reflection.
     * - The accessor is a string alias bound in our Testbench container (first-party
     *   services like `'cache'`, `'router'`, package bindings registered via discovered
     *   providers).
     *
     * Returns null when the accessor is a string alias bound only by a user service
     * provider that does not run in Testbench — nothing we can do at this layer.
     *
     * @return ?class-string
     */
    public static function tryGetFacadeRootClass(string $facadeClass): ?string
    {
        // is_subclass_of() invokes the autoloader; guard per FacadeMapProvider::init().
        try {
            if (!\is_subclass_of($facadeClass, Facade::class)) {
                return null;
            }

            /** @var mixed $root — getFacadeRoot() is untyped and container bindings can resolve to anything */
            $root = $facadeClass::getFacadeRoot();
        } catch (\Throwable) {
            return null;
        }

        return \is_object($root) ? \get_class($root) : null;
    }

    public static function doesMethodExist(MethodExistenceProviderEvent $event, string $rootClass): ?bool
    {
        $source = $event->getSource();

        if (!$source instanceof StatementsSource) {
            return null;
        }

        // Null (not false) so other resolution paths keep firing — returning false
        // would actively assert the method does NOT exist and suppress @method/@mixin.
        return self::resolveMethod(
            $source->getCodebase(),
            $event->getFqClasslikeName(),
            $event->getMethodNameLowercase(),
            $rootClass,
        ) instanceof MethodStorage ? true : null;
    }

    /**
     * @return list<FunctionLikeParameter>|null
     */
    public static function getMethodParams(MethodParamsProviderEvent $event, string $rootClass): ?array
    {
        $source = $event->getStatementsSource();

        if (!$source instanceof StatementsSource) {
            return null;
        }

        $storage = self::resolveMethod(
            $source->getCodebase(),
            $event->getFqClasslikeName(),
            $event->getMethodNameLowercase(),
            $rootClass,
        );

        return $storage?->params;
    }

    public static function getReturnType(MethodReturnTypeProviderEvent $event, string $rootClass): ?Union
    {
        $storage = self::resolveMethod(
            $event->getSource()->getCodebase(),
            $event->getFqClasslikeName(),
            $event->getMethodNameLowercase(),
            $rootClass,
        );

        if (!$storage instanceof MethodStorage) {
            return null;
        }

        // AtomicStaticCallAnalyzer only commits the resolved type when the return type
        // provider yields a non-falsy Union. A method with no declared return type
        // would otherwise fall through to the UndefinedMethod path, even though
        // existence_provider claimed the method. Default to mixed so the call
        // succeeds — users can narrow via `@method` on the facade when needed.
        return $storage->return_type ?? Type::getMixed();
    }

    /** @psalm-external-mutation-free */
    private static function resolveMethod(
        Codebase $codebase,
        string $facadeClass,
        string $methodNameLower,
        string $rootClass,
    ): ?MethodStorage {
        $key = $facadeClass . '::' . $methodNameLower;

        if (\array_key_exists($key, self::$methodCache)) {
            return self::$methodCache[$key];
        }

        // Defer to `@method` when the user declared one — their intent wins.
        //
        // This check is NOT redundant with Psalm's lookup order. For static calls,
        // AtomicStaticCallAnalyzer computes `naive_method_exists` with `$with_pseudo=false`,
        // so `@method` (pseudo_static_methods) is NOT part of the naive lookup. When our
        // return_type_provider also runs inside the `__callStatic` branch, it is consulted
        // BEFORE `checkPseudoMethod`. Without this short-circuit, a facade with
        // `@method static bool isPlus()` AND an underlying `isPlus(): string` would see our
        // `string` win over the declared `bool` — reversing user intent.
        if (self::hasPseudoStaticMethod($codebase, $facadeClass, $methodNameLower)) {
            return self::$methodCache[$key] = null;
        }

        return self::$methodCache[$key] = self::lookupPublicMethod($codebase, $rootClass, $methodNameLower);
    }

    /**
     * Test whether a facade (or any ancestor via class_implements / parent_classes / used_traits)
     * declares `@method $methodNameLower`. Psalm's Populator already merges ancestor
     * pseudo-methods into child `pseudo_static_methods`, so walking ancestors is a superset
     * kept defensively against Populator changes.
     *
     * @psalm-external-mutation-free
     */
    private static function hasPseudoStaticMethod(
        Codebase $codebase,
        string $facadeClass,
        string $methodNameLower,
    ): bool {
        if (!isset(self::$pseudoMethodCache[$facadeClass])) {
            self::$pseudoMethodCache[$facadeClass] = self::collectPseudoStaticMethods($codebase, $facadeClass);
        }

        return isset(self::$pseudoMethodCache[$facadeClass][$methodNameLower]);
    }

    /**
     * @return array<lowercase-string, MethodStorage>
     * @psalm-mutation-free
     */
    private static function collectPseudoStaticMethods(Codebase $codebase, string $facadeClass): array
    {
        try {
            $storage = $codebase->classlike_storage_provider->get($facadeClass);
        } catch (\InvalidArgumentException) {
            return [];
        }

        $methods = $storage->pseudo_static_methods;

        $ancestors = $storage->parent_classes + $storage->class_implements + $storage->used_traits;

        foreach (\array_keys($ancestors) as $ancestorLower) {
            try {
                $ancestorStorage = $codebase->classlike_storage_provider->get($ancestorLower);
            } catch (\InvalidArgumentException) {
                continue;
            }

            $methods += $ancestorStorage->pseudo_static_methods;
        }

        return $methods;
    }

    /** @psalm-mutation-free */
    private static function lookupPublicMethod(
        Codebase $codebase,
        string $className,
        string $methodNameLower,
    ): ?MethodStorage {
        try {
            $storage = $codebase->classlike_storage_provider->get($className);
        } catch (\InvalidArgumentException) {
            return null;
        }

        $declaringId = $storage->declaring_method_ids[$methodNameLower] ?? null;

        if ($declaringId === null) {
            return null;
        }

        try {
            $methodStorage = $codebase->methods->getStorage($declaringId);
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            return null;
        }

        // Non-public methods are intentionally not surfaced on the facade — mirrors runtime
        // behaviour (only public methods are callable through `__callStatic`) and keeps us
        // out of Psalm's visibility-provider layer.
        if ($methodStorage->visibility !== ClassLikeAnalyzer::VISIBILITY_PUBLIC) {
            return null;
        }

        return $methodStorage;
    }
}
