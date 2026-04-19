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
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Storage\MethodStorage;
use Psalm\Type;
use Psalm\Type\Union;

/**
 * Resolves `FacadeClass::method()` calls for app-owned Facade subclasses that do
 * not enumerate every forwarded method in `@method`.
 *
 * For a facade like `App\Facades\License extends Facade` with `getFacadeAccessor()`
 * returning a string alias `'License'`, the underlying service cannot be resolved
 * through our Testbench container (the user's service provider is not executed),
 * so `FacadeMapProvider` cannot recover the binding. This handler discovers the
 * concrete service through a `@see \App\Services\LicenseService` docblock pointer
 * (path 3 in the spec) and falls back to a runtime `getFacadeRoot()` probe when
 * the binding happens to resolve in Testbench (path 4).
 *
 * Resolution order, with the parts we delegate and the parts we enforce:
 * - Real methods win natively — Psalm's `naive_method_exists` at
 *   AtomicStaticCallAnalyzer:351-364 finds them before our existence_provider runs.
 * - `@mixin` chains resolve natively too (AtomicStaticCallAnalyzer:381-473) and
 *   are the idiomatic Laravel convention for real-time facades (`php artisan make:facade`
 *   stubs emit `@mixin`). We do NOT parse `@mixin` ourselves — duplicating that
 *   would race with Psalm's mixin walk.
 * - `@method` declarations are NOT part of `naive_method_exists` (it runs with
 *   `with_pseudo=false`). Without intervention, our return_type_provider inside the
 *   `__callStatic` branch at AtomicStaticCallAnalyzer:610-636 would execute BEFORE
 *   `checkPseudoMethod` at :639 — reversing user intent. {@see resolveMethod()}
 *   adds an explicit short-circuit via {@see hasPseudoStaticMethod()} to defer to
 *   `@method` when declared.
 * - `@see` (this class) is the complementary fallback for facades that avoid
 *   `@mixin`'s static/instance propagation (e.g. koel's `License`).
 *
 * Registered per-facade by {@see AppFacadeRegistrationHandler}.
 *
 * @see \Psalm\LaravelPlugin\Handlers\Facades\AppFacadeRegistrationHandler
 * @internal
 */
final class FacadeMethodHandler
{
    /**
     * @var array<string, list<class-string>> facadeClass => FQCNs resolved from `@see` tags in declaration order
     *
     * Multiple `@see` entries are expected — first-party Laravel facades (Cache, Auth, etc.)
     * document both a manager and a repository, and user facades often mimic the convention.
     * Resolution iterates in docblock order; the first target that has the requested method wins.
     */
    private static array $seeCache = [];

    /**
     * @var array<string, ?MethodStorage> "facade::method_lower" => resolved underlying method, or null
     */
    private static array $methodCache = [];

    /**
     * @var array<string, ?class-string> facadeClass => concrete root class from `getFacadeRoot()` (path 4), or null
     *
     * Cached per facade — without this, every unresolved method name would re-invoke
     * container resolution and potentially re-run user service providers.
     */
    private static array $facadeRootCache = [];

    /**
     * @var array<string, array<lowercase-string, true>> facadeClass => {method_lower => true} union of @method tags
     *
     * Cached per facade so the `hasPseudoStaticMethod` gate is O(1) amortised instead of
     * a per-call ancestor-chain walk.
     */
    private static array $pseudoMethodCache = [];

    public static function doesMethodExist(MethodExistenceProviderEvent $event): ?bool
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
        ) instanceof \Psalm\Storage\MethodStorage ? true : null;
    }

    /**
     * @return list<FunctionLikeParameter>|null
     */
    public static function getMethodParams(MethodParamsProviderEvent $event): ?array
    {
        $source = $event->getStatementsSource();

        if (!$source instanceof StatementsSource) {
            return null;
        }

        $storage = self::resolveMethod(
            $source->getCodebase(),
            $event->getFqClasslikeName(),
            $event->getMethodNameLowercase(),
        );

        return $storage?->params;
    }

    public static function getReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $storage = self::resolveMethod(
            $event->getSource()->getCodebase(),
            $event->getFqClasslikeName(),
            $event->getMethodNameLowercase(),
        );

        if (!$storage instanceof \Psalm\Storage\MethodStorage) {
            return null;
        }

        // AtomicStaticCallAnalyzer only commits the resolved type when the return type
        // provider yields a non-falsy Union (line 623). A method with no declared
        // return type would otherwise fall through to the UndefinedMethod path, even
        // though existence_provider claimed the method. Default to mixed so the call
        // succeeds — users can narrow via `@method` on the facade when needed.
        return $storage->return_type ?? Type::getMixed();
    }

    /**
     * Parse `@see` tags from a facade's docblock and resolve them to concrete FQCNs.
     * Returns every resolvable target in declaration order — first-party Laravel facades
     * (Cache, Auth, Bus, etc.) document both a manager and a repository, so callers that
     * need the full list to query every target must iterate the result.
     *
     * Used at three points: during class-like visit (to queue each referenced class for
     * scanning), at registration time (to extend {@see FacadeMapProvider}), and at method
     * resolution time.
     *
     * @return list<class-string>
     */
    public static function resolveSeeTargets(Codebase $codebase, string $facadeClass): array
    {
        if (\array_key_exists($facadeClass, self::$seeCache)) {
            return self::$seeCache[$facadeClass];
        }

        try {
            $storage = $codebase->classlike_storage_provider->get($facadeClass);
        } catch (\InvalidArgumentException) {
            return self::$seeCache[$facadeClass] = [];
        }

        return self::resolveSeeTargetsFromStorage($facadeClass, $storage);
    }

    /**
     * Variant of {@see resolveSeeTargets()} that accepts an already-fetched
     * {@see ClassLikeStorage}. Use during the scan phase when the storage is available
     * directly from the visit event — avoids a redundant classlike_storage_provider lookup.
     *
     * @return list<class-string>
     */
    public static function resolveSeeTargetsFromStorage(string $facadeClass, ClassLikeStorage $storage): array
    {
        if (\array_key_exists($facadeClass, self::$seeCache)) {
            return self::$seeCache[$facadeClass];
        }

        // Anonymous classes and synthetic names have no usable name-resolution context.
        if (!$storage->aliases instanceof \Psalm\Aliases) {
            return self::$seeCache[$facadeClass] = [];
        }

        try {
            /** @var class-string $facadeClass — caller has already invoked class_exists() / is a scanned classlike */
            $reflection = new \ReflectionClass($facadeClass);
        } catch (\ReflectionException) {
            return self::$seeCache[$facadeClass] = [];
        }

        $docComment = $reflection->getDocComment();

        if ($docComment === false) {
            return self::$seeCache[$facadeClass] = [];
        }

        $resolved = [];

        foreach (self::extractSeeCandidates($docComment) as $candidate) {
            $target = self::resolveRelativeName(
                $candidate,
                $storage->aliases->uses,
                $storage->aliases->namespace,
            );

            if ($target !== null && !\in_array($target, $resolved, true)) {
                /** @var class-string $target — resolveRelativeName returns only names that passed class_exists() */
                $resolved[] = $target;
            }
        }

        return self::$seeCache[$facadeClass] = $resolved;
    }

    /**
     * Extract class-reference candidates from `@see` tags in a docblock.
     *
     * Recognises the first whitespace-delimited token after each `@see`. Tokens
     * are filtered to drop URLs (contain `://`), `{@link ...}` forms, and empty
     * results after stripping a trailing `::method` or `::$property` selector.
     *
     * @return list<string>
     * @psalm-pure
     */
    public static function extractSeeCandidates(string $docComment): array
    {
        \preg_match_all('/@see\s+(\S+)/', $docComment, $matches);

        $tokens = $matches[1] ?? [];
        $candidates = [];

        foreach ($tokens as $token) {
            // URL forms: `@see https://laravel.com/docs`
            if (\str_contains($token, '://')) {
                continue;
            }

            // Inline link forms: `@see {@link Foo}` — skip, rare and not a bare classref.
            if (\str_starts_with($token, '{')) {
                continue;
            }

            // Strip a trailing `::method` or `::$property` selector.
            $separator = \strpos($token, '::');

            if ($separator !== false) {
                $token = \substr($token, 0, $separator);
            }

            if ($token === '' || $token === '\\') {
                continue;
            }

            $candidates[] = $token;
        }

        return $candidates;
    }

    /**
     * Resolve a (possibly relative) class reference to an existing FQCN.
     *
     * Name resolution mirrors PHP's rules for class names in docblocks:
     * a leading `\` marks a fully-qualified name; otherwise the first segment
     * is matched against `use` imports first, then against the current namespace.
     * A bare name in the global namespace is also attempted as a last resort.
     * The first candidate whose class exists (via `class_exists(..., true)`) wins.
     *
     * @param array<lowercase-string, string> $uses use-import map (lowercase alias => FQCN)
     */
    public static function resolveRelativeName(string $name, array $uses, ?string $namespace): ?string
    {
        if ($name === '') {
            return null;
        }

        // Leading backslash: global / fully-qualified.
        if (\str_starts_with($name, '\\')) {
            $fqcn = \substr($name, 1);

            return self::classExistsSafe($fqcn) ? $fqcn : null;
        }

        $firstSeparator = \strpos($name, '\\');
        $firstSegment = $firstSeparator === false ? $name : \substr($name, 0, $firstSeparator);
        $restPath = $firstSeparator === false ? '' : \substr($name, $firstSeparator);
        $firstLower = \strtolower($firstSegment);

        // Use-import: `use App\Services\LicenseService` + `@see LicenseService`
        // => $uses['licenseservice'] === 'App\Services\LicenseService'.
        if (isset($uses[$firstLower])) {
            $candidate = $uses[$firstLower] . $restPath;

            if (self::classExistsSafe($candidate)) {
                return $candidate;
            }
        }

        // Namespace-relative: within namespace X, `@see Y\Z` => X\Y\Z.
        if ($namespace !== null && $namespace !== '') {
            $candidate = $namespace . '\\' . $name;

            if (self::classExistsSafe($candidate)) {
                return $candidate;
            }
        }

        // Last resort: user wrote a bare global class name without a leading slash.
        if (self::classExistsSafe($name)) {
            return $name;
        }

        return null;
    }

    private static function resolveMethod(
        Codebase $codebase,
        string $facadeClass,
        string $methodNameLower,
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
        // BEFORE `checkPseudoMethod` (see `AtomicStaticCallAnalyzer.php:610-636` vs `:639`).
        // Without this short-circuit, a facade with `@method static bool isPlus()` AND a
        // `@see` target that exposes `isPlus(): string` would see our `string` win over the
        // declared `bool` — reversing user intent.
        if (self::hasPseudoStaticMethod($codebase, $facadeClass, $methodNameLower)) {
            return self::$methodCache[$key] = null;
        }

        // Path 3: @see docblock pointer — solves the koel case (accessor unbound in Testbench).
        // Iterate every resolvable @see target; first one that owns the method wins. Matches
        // Laravel's multi-@see convention (e.g. Cache facade points at CacheManager + Repository).
        foreach (self::resolveSeeTargets($codebase, $facadeClass) as $rootClass) {
            $resolved = self::lookupPublicMethod($codebase, $rootClass, $methodNameLower);

            if ($resolved instanceof \Psalm\Storage\MethodStorage) {
                return self::$methodCache[$key] = $resolved;
            }
        }

        // Path 4: getFacadeRoot() runtime probe. Only resolves for facades whose accessor
        // happens to bind in our Testbench container (first-party services like 'cache',
        // 'router', package bindings registered via discovered providers).
        $rootClass = self::tryGetFacadeRootClass($facadeClass);

        if ($rootClass !== null) {
            $resolved = self::lookupPublicMethod($codebase, $rootClass, $methodNameLower);

            if ($resolved instanceof \Psalm\Storage\MethodStorage) {
                return self::$methodCache[$key] = $resolved;
            }
        }

        return self::$methodCache[$key] = null;
    }

    /**
     * Test whether a facade (or any ancestor via class_implements / parent_classes) declares
     * `@method $methodNameLower`. Mirrors Psalm's own
     * `AtomicStaticCallAnalyzer::findPseudoMethodAndClassStorages()`, but caches the union
     * of declared pseudo method names per facade so the resolver gate is O(1) amortised.
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
     * @return array<lowercase-string, true>
     * @psalm-mutation-free
     */
    private static function collectPseudoStaticMethods(Codebase $codebase, string $facadeClass): array
    {
        try {
            $storage = $codebase->classlike_storage_provider->get($facadeClass);
        } catch (\InvalidArgumentException) {
            return [];
        }

        $methods = [];

        foreach (\array_keys($storage->pseudo_static_methods) as $name) {
            $methods[$name] = true;
        }

        // parent_classes + class_implements + used_traits covers every source Psalm itself
        // consults for pseudo methods. Omitting used_traits would cause our resolver to
        // override a trait-declared @method — the exact regression the hasPseudoStaticMethod
        // gate exists to prevent.
        $ancestors = $storage->parent_classes + $storage->class_implements + $storage->used_traits;

        foreach (\array_keys($ancestors) as $ancestorLower) {
            try {
                $ancestorStorage = $codebase->classlike_storage_provider->get($ancestorLower);
            } catch (\InvalidArgumentException) {
                continue;
            }

            foreach (\array_keys($ancestorStorage->pseudo_static_methods) as $name) {
                $methods[$name] = true;
            }
        }

        return $methods;
    }

    /**
     * Resolve the facade's container-bound root object and return its class. Cached per
     * facade — without the cache every unresolved method name would re-invoke container
     * resolution and potentially re-run user service providers.
     *
     * @return ?class-string
     */
    private static function tryGetFacadeRootClass(string $facadeClass): ?string
    {
        if (\array_key_exists($facadeClass, self::$facadeRootCache)) {
            return self::$facadeRootCache[$facadeClass];
        }

        // is_subclass_of() invokes the autoloader; guard per FacadeMapProvider::init().
        try {
            if (!\is_subclass_of($facadeClass, Facade::class)) {
                return self::$facadeRootCache[$facadeClass] = null;
            }

            /** @var mixed $root — getFacadeRoot() is untyped and container bindings can resolve to anything */
            $root = $facadeClass::getFacadeRoot();
        } catch (\Throwable) {
            return self::$facadeRootCache[$facadeClass] = null;
        }

        return self::$facadeRootCache[$facadeClass] = \is_object($root) ? \get_class($root) : null;
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
        // out of Psalm's visibility-provider layer (see spec "Simplifications applied to v1").
        if ($methodStorage->visibility !== ClassLikeAnalyzer::VISIBILITY_PUBLIC) {
            return null;
        }

        return $methodStorage;
    }

    private static function classExistsSafe(string $class): bool
    {
        try {
            return \class_exists($class, true);
        } catch (\Error|\Exception) {
            return false;
        }
    }
}
