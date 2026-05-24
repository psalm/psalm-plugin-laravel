<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers;

use Psalm\Codebase;
use Psalm\LaravelPlugin\Util\Ast\CachedClosureTypeFactory;
use Psalm\LaravelPlugin\Util\Ast\ClosureTypeFactory;
use Psalm\Progress\Progress;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Storage\FunctionLikeStorage;
use Psalm\Type;
use Psalm\Type\Union;

/**
 * Registry of macros discovered via runtime reflection of the booted Laravel app's
 * `Macroable::$macros` static properties.
 *
 * Foundation for issue #758 (Strategy B). Populated from
 * {@see \Psalm\LaravelPlugin\Handlers\Magic\MacroHandler::afterCodebasePopulated()}
 * (NOT from `Plugin::__invoke`) because Psalm's `autoloader` config attribute fires
 * during analyser setup — after plugin entry points but before `AfterCodebasePopulated`.
 * Discovering at boot time would miss any macro registered in code reachable only
 * through the autoloader (e.g. test fixtures, or — for the Testbench fallback path
 * where the analysed package lacks a `bootstrap/app.php` — autoload-time service
 * provider initialisation).
 *
 * The lifecycle therefore differs from {@see FacadeMapProvider}, which IS populated
 * at plugin init: facades are registered by Laravel's bootstrap, which has run by
 * then. Macros include user-app and test-time registrations that haven't.
 *
 * Coverage:
 * - **App analysis** (`bootstrap/app.php` exists): catches macros registered by user
 *   `App\Providers\*::boot()`, third-party packages auto-discovered via Laravel
 *   package discovery, and any framework-internal macros (`Request::validate`,
 *   `Request::hasValidSignature`, etc. registered by `FoundationServiceProvider`).
 * - **Package analysis** (Testbench fallback): does not run the analysed package's
 *   own provider — see issue #766. Strategy C (AST scan) will close that gap.
 *
 * Detection heuristic: a class is treated as Macroable-shaped if it has a static
 * `$macros` property AND exposes one of `__call`, `__callStatic`, or `macro()`.
 * This catches the `Macroable` trait users (Stringable, Str, Builder/Query, Request,
 * etc.) and `\Illuminate\Database\Eloquent\Builder`, which declares its own
 * `$macros` storage and dispatch methods without using the trait. Spurious matches
 * are filtered downstream by the per-callable shape check in
 * {@see self::reflectCallable()} and the real-method shadow check in
 * {@see self::init()}.
 *
 * @internal
 */
final class MacroRegistry
{
    /**
     * @var array<lowercase-string, array<lowercase-string, MacroDefinition>>
     *      classFqcn (lower) => methodName (lower) => def
     */
    private static array $macros = [];

    /** @var list<class-string> Macroable-shaped classes that have at least one resolved macro */
    private static array $knownMacroableClasses = [];

    /**
     * Discover macros from every loaded class with a static `$macros` array property.
     *
     * Reads the property via `ReflectionProperty::getValue()` directly. Since PHP 8.1,
     * `ReflectionProperty` has been accessible by default — no `setAccessible(true)`
     * call needed for the protected `Macroable::$macros` storage. Closures that fail
     * to resolve (e.g. closure was bound to a since-unloaded class) are silently
     * skipped to keep one bad macro from disabling the rest.
     *
     * Filter order is performance-sensitive: `get_declared_classes()` returns the
     * full PHP runtime class table (10k+ entries on a real Laravel app). We start
     * with the cheapest possible reject (`hasProperty('macros')`) and only progress
     * to the more expensive checks for the small surviving set.
     *
     * Idempotent: reruns reset state. Tests rely on this for isolation.
     *
     * @param Codebase|null $codebase When supplied, closure macros are matched against
     *        Psalm's pre-scanned {@see FunctionLikeStorage} so docblock-derived param
     *        and return types are recovered ({@see self::recoverClosureStorage()}).
     *        Null is the unit-test seam: extraction degrades to reflection-only native
     *        types, identical to the pre-storage behaviour.
     */
    public static function init(Progress $progress, ?Codebase $codebase = null): void
    {
        self::$macros = [];
        self::$knownMacroableClasses = [];

        foreach (\get_declared_classes() as $className) {
            try {
                $reflection = new \ReflectionClass($className);
            } catch (\ReflectionException) {
                continue;
            }

            // Cheapest reject first — O(1) hash probe against PHP's resolved class
            // info, which already includes inherited properties (so this also fires
            // for any subclass of a Macroable-trait user).
            if (!$reflection->hasProperty('macros')) {
                continue;
            }

            $macroProp = $reflection->getProperty('macros');
            if (!$macroProp->isStatic()) {
                continue;
            }

            // Subclass entries inherit the parent's storage. Only register against the
            // class that DECLARES the property — otherwise we'd record the same macro
            // dozens of times (once per descendant). The handler propagates pseudo-
            // methods to descendants explicitly so this isn't a coverage loss.
            // Performance bonus: this filter runs BEFORE getValue(), avoiding the
            // costly read on every analysed subclass of a Macroable parent.
            $declaringClass = $macroProp->getDeclaringClass()->getName();
            if ($declaringClass !== $className) {
                continue;
            }

            // Tighten the heuristic: a class with a static `$macros` array is treated
            // as Macroable-shaped only if it also exposes a runtime dispatch surface
            // (`__call` / `__callStatic` / a static `macro()` method). This avoids
            // false positives for unrelated classes that happen to declare a `$macros`
            // static property for their own purposes.
            if (
                !$reflection->hasMethod('__call')
                && !$reflection->hasMethod('__callStatic')
                && !$reflection->hasMethod('macro')
            ) {
                continue;
            }

            try {
                // Macroable::$macros is documented as `array<string, callable>` and
                // populated via the `macro()` API, which type-hints `callable`. We
                // expand `callable` to its structural union so downstream helpers
                // can accept it without triggering PHP's strict call-time
                // `is_callable()` check (see `buildDefinition()`). Keys are typed as
                // `array-key` rather than `string` because the Macroable-shape
                // heuristic above can let through classes whose `$macros` static
                // happens to use integer keys; the `is_string($name)` guard below
                // is the runtime defense against that.
                /** @psalm-var array<array-key, \Closure|non-empty-string|array{0: object|class-string, 1: non-empty-string}|object>|null $rawMacros */
                $rawMacros = $macroProp->getValue();
            } catch (\Throwable $exception) {
                // `getValue()` on a typed static property that is uninitialised throws
                // `\Error`, not `ReflectionException`. Catch broadly so a single bad
                // class — including one whose static initialiser throws — cannot
                // abort discovery for the rest.
                $progress->warning(
                    "Laravel plugin: MacroRegistry could not read \$macros on {$className}: {$exception->getMessage()}",
                );
                continue;
            }

            if (!\is_array($rawMacros) || $rawMacros === []) {
                continue;
            }

            $classMacros = [];
            foreach ($rawMacros as $name => $callable) {
                if (!\is_string($name) || $name === '') {
                    continue;
                }

                // Skip macros whose name shadows a real method on the class.
                // PHP's method resolution checks declared methods before falling through
                // to __call, so the macro is unreachable at runtime — the real method
                // wins. The handler that injects pseudo-methods also guards against this
                // (a real `methods[$name]` entry blocks the pseudo-method write), but
                // filtering here keeps the registry honest about what's actually callable.
                if ($reflection->hasMethod($name)) {
                    continue;
                }

                $def = self::buildDefinition($className, $name, $callable, $progress, $codebase);
                if (!$def instanceof \Psalm\LaravelPlugin\Providers\MacroDefinition) {
                    continue;
                }

                $classMacros[\strtolower($name)] = $def;
            }

            if ($classMacros === []) {
                continue;
            }

            self::$macros[\strtolower($className)] = $classMacros;
            self::$knownMacroableClasses[] = $className;
        }

        // Drop the AST-scan cache now that every macro definition is built.
        // The cache holds full `Closure` / `ArrowFunction` PhpParser subtrees
        // per visited file; once we're past the foreach above, none of those
        // nodes are reachable from `MacroDefinition` instances anymore. Holding
        // them until session teardown would (a) keep the AST pinned for the
        // rest of analysis and (b) replicate it into every pcntl fork worker
        // via copy-on-write, which is exactly the cost we wanted to avoid by
        // not re-parsing in the first place.
        CachedClosureTypeFactory::reset();
    }

    /**
     * All macros registered on the given Macroable class.
     *
     * @return array<lowercase-string, MacroDefinition>
     * @psalm-external-mutation-free
     */
    public static function for(string $fqcn): array
    {
        return self::$macros[\strtolower($fqcn)] ?? [];
    }

    /**
     * Single macro by class + method name, or `null` if not registered.
     *
     * @psalm-api Used by unit tests and any handler that needs to look up a single macro
     *            without enumerating the whole class. The handler currently uses {@see self::for()}.
     * @psalm-external-mutation-free
     */
    public static function get(string $fqcn, string $methodName): ?MacroDefinition
    {
        return self::$macros[\strtolower($fqcn)][\strtolower($methodName)] ?? null;
    }

    /**
     * @return list<class-string> Macroable-shaped classes that have at least one macro
     * @psalm-external-mutation-free
     */
    public static function getKnownMacroableClasses(): array
    {
        return self::$knownMacroableClasses;
    }

    /**
     * Test seam: replace the registry contents in tests without going through {@see init()}.
     *
     * @param array<lowercase-string, array<lowercase-string, MacroDefinition>> $macros
     * @param list<class-string> $knownMacroableClasses
     * @psalm-api Tests only.
     * @psalm-external-mutation-free
     */
    public static function overrideForTesting(array $macros, array $knownMacroableClasses): void
    {
        self::$macros = $macros;
        self::$knownMacroableClasses = $knownMacroableClasses;
    }

    /**
     * Reset registry to empty state. Tests use this to isolate runs.
     *
     * @psalm-api Tests only.
     * @psalm-external-mutation-free
     */
    public static function reset(): void
    {
        self::$macros = [];
        self::$knownMacroableClasses = [];
        // Cascade into the AST docblock cache — tests rely on this for isolation
        // between runs, and the cache is keyed by realpath+mtime so leaking it
        // across test cases would produce phantom hits on fixture files that
        // were rewritten between runs.
        CachedClosureTypeFactory::reset();
    }

    /**
     * Build a {@see MacroDefinition} from a registered callable.
     *
     * Handles every callable shape Laravel's `Macroable::__call` dispatches:
     *
     * - `Closure` (the common case)
     * - String `'ClassName::method'` — reflected as a static method
     * - String `'function_name'` — reflected as a function
     * - Array `[ClassName::class, 'method']` or `[$obj, 'method']` — reflected as a method
     * - Invokable object (any object with `__invoke`) — reflected as `__invoke`
     *
     * Each shape is reduced to a `\ReflectionFunctionAbstract` and the same param /
     * return-type extraction runs.
     *
     * The `$callable` union mirrors every dispatchable shape but uses a structural
     * union instead of PHP's `callable` pseudo-type. Callable validation at the
     * parameter boundary would reject array shapes whose target isn't actually
     * callable (`[Class::class, 'protectedMethod']`, non-static via class-string),
     * preventing the body's visibility/static checks from running and surfacing
     * the rejection. Mirrors `reflectCallable()`.
     *
     * @param class-string $declaringClass
     * @param \Closure|non-empty-string|array{0: object|class-string, 1: non-empty-string}|object $callable
     * @param Codebase|null $codebase Optional Psalm codebase used to recover
     *        docblock-aware closure types from already-scanned source
     *        ({@see self::recoverClosureStorage()}). Null when called from a unit-test
     *        context that constructs the registry without booting Psalm.
     */
    private static function buildDefinition(
        string $declaringClass,
        string $name,
        string|array|object $callable,
        Progress $progress,
        ?Codebase $codebase = null,
    ): ?MacroDefinition {
        $reflection = self::reflectCallable($callable, $declaringClass, $name, $progress);
        if (!$reflection instanceof \ReflectionFunctionAbstract) {
            return null;
        }

        // Docblock-aware closure type extraction (Strategy C / issue #899 idea #1,
        // expanded by issue #991).
        //
        // Two recovery paths in priority order:
        //
        // 1. **AST scan** ({@see CachedClosureTypeFactory}, wrapping
        //    {@see ClosureTypeFactory}): builds a {@see TClosure} from the
        //    closure's
        //    source file with `nikic/php-parser`, locates the closure by start
        //    line, and lifts `@param` / `@return` from the docblock attached
        //    either directly to the closure node OR to the wrapping
        //    `Stmt\Expression` (Inertia's `Router::macro('inertia', fn () { ... })`
        //    pattern). This works regardless of whether Psalm scanned the file,
        //    so it catches both project source and vendor packages outside
        //    `<projectFiles>`.
        //
        // 2. **Psalm storage** ({@see self::recoverClosureStorage()}): looks up
        //    Psalm's pre-scanned {@see FunctionLikeStorage} for the closure. This
        //    only fires when AST scan yielded nothing — file unreadable /
        //    unparseable, no closure starts at the reflected line, or no docblock.
        //    For closures with a docblock attached directly to the closure node,
        //    storage and AST produce the same types; for the Stmt\Expression
        //    docblock pattern, storage's scan does not attach the outer docblock
        //    to the inner closure, which is exactly why AST is tried first.
        //
        // 3. Falls through to **native reflection** when neither path yields data.
        $closureType = null;
        if ($callable instanceof \Closure) {
            $closureType = CachedClosureTypeFactory::fromClosureObject($callable);

            if (!$closureType instanceof \Psalm\Type\Atomic\TClosure && $codebase instanceof \Psalm\Codebase) {
                $closureStorage = self::recoverClosureStorage($reflection, $codebase);
                if ($closureStorage instanceof FunctionLikeStorage) {
                    return self::buildDefinitionFromStorage($declaringClass, $name, $closureStorage);
                }
            }
        }

        // For Closure callables, the factory's TClosure already carries the
        // narrowed params + return type. Unpack and short-circuit the rest of
        // the build pipeline — no `self`/`static` host expansion needed here
        // (closures don't bind a host class at definition site; see the
        // long-form comment below).
        if ($closureType instanceof \Psalm\Type\Atomic\TClosure) {
            return new MacroDefinition(
                declaringClass: $declaringClass,
                methodName: \strtolower($name),
                casedName: $name,
                params: $closureType->params ?? [],
                returnType: $closureType->return_type ?? Type::getMixed(),
                // `signature_return_type` carries only the native PHP type;
                // grab it from reflection so the docblock-narrowed
                // `return_type` and the native `signature_return_type` stay
                // independent slots.
                signatureReturnType: self::reflectionTypeToUnion($reflection->getReturnType()),
            );
        }

        // Native `self`/`static`/`parent` references in a `\ReflectionMethod`'s signature
        // resolve relative to the method's class, not the Macroable host. We expand them
        // before parsing.
        //
        // - `self` and `parent` always resolve to the method's *declaring* class / its parent.
        // - `static` is late-static-binding. For an object callable `[$obj, 'method']` it
        //   refers to the runtime class of `$obj`, which can be a subclass of the declaring
        //   class. The conservative-but-correct approximation is `get_class($obj)`. For
        //   `[Class::class, 'method']` and `'Class::method'` string callables there's no
        //   instance to bind to, so `static` collapses to the declaring class.
        //
        // **Why this branch deliberately excludes Closure callables.** Macroable's
        // `__call` rebinds closure macros via `bindTo($this, static::class)`, so
        // `static` in a closure body resolves to the call site's class, not the
        // declaring class. Leaving `$selfHostClass`/`$staticHostClass` null for
        // closures keeps the literal `static` token in the parsed return type
        // (`TNamedObject('static')`), which Psalm's pseudo-method dispatch then
        // expands against the lhs caller via `TypeExpander::expandUnion` —
        // delivering fluent narrowing on `: static` closure return types. Issue
        // #899 §C signal 1; locked in by
        // `tests/Type/tests/Macros/MacroFluentStaticTest.phpt`.
        $selfHostClass = null;
        $staticHostClass = null;
        if ($reflection instanceof \ReflectionMethod) {
            $selfHostClass = $reflection->getDeclaringClass()->getName();
            $staticHostClass = $selfHostClass;
            if (\is_array($callable) && isset($callable[0]) && \is_object($callable[0])) {
                $staticHostClass = \get_class($callable[0]);
            } elseif (\is_object($callable) && !$callable instanceof \Closure) {
                $staticHostClass = \get_class($callable);
            }
        }

        // Non-closure callables (string `'Class::method'`, array
        // `[$obj, 'method']`, invokable objects) reach this branch. Their
        // docblock — if any — lives on the resolved method/function and is
        // recovered by Psalm's normal scan, so no AST extraction is needed
        // here; reflection alone produces the param + return types.
        $params = [];
        foreach ($reflection->getParameters() as $reflParam) {
            $params[] = self::buildParameter($reflParam, $selfHostClass, $staticHostClass);
        }

        // `$nativeReturnType === null` is "callable declared no native return type", which is
        // distinct from "declared `mixed`". The former leaves `signature_return_type`
        // null per Psalm convention; the latter would set it to a real Union.
        $nativeReturnType = self::reflectionTypeToUnion($reflection->getReturnType(), $selfHostClass, $staticHostClass);

        return new MacroDefinition(
            declaringClass: $declaringClass,
            methodName: \strtolower($name),
            casedName: $name,
            params: $params,
            returnType: $nativeReturnType ?? Type::getMixed(),
            signatureReturnType: $nativeReturnType,
        );
    }

    /**
     * Find Psalm's {@see FunctionLikeStorage} for a closure that Psalm has scanned.
     *
     * Psalm keys closure storage as `<lowercase-path>:<line>:<startFilePos>:-:closure`
     * (see the closure branch in
     * {@see \Psalm\Internal\PhpVisitor\Reflector\FunctionLikeNodeScanner}). Reflection
     * gives us the file and line cheaply but not the byte offset — so we scan the
     * file's storage table for any closure entry on the same line.
     *
     * Match policy:
     * - Zero matches: source not scanned (vendor or out-of-scope path, eval'd code, or
     *   missing-file edge cases). Returns null; caller falls back to reflection.
     * - One match: returned. Common case.
     * - Multiple matches: two or more closures starting on the same line (rare; e.g.
     *   inline `[fn() => 1, fn() => 2]`). Reflection cannot disambiguate by byte
     *   offset, so we return null rather than guess.
     *
     * Path normalisation: Psalm lowercases the path when keying storage
     * ({@see \Psalm\Internal\Provider\FileStorageProvider::get}). The reflected file
     * name is run through `realpath()` first to canonicalise symlinks and `..`
     * segments, then lowercased and separator-normalised to match Psalm's key shape.
     */
    private static function recoverClosureStorage(
        \ReflectionFunctionAbstract $reflection,
        Codebase $codebase,
    ): ?FunctionLikeStorage {
        $filePath = $reflection->getFileName();
        $line = $reflection->getStartLine();
        if (!\is_string($filePath) || !\is_int($line)) {
            // Internal closures lack a source location (`getFileName()` returns false).
            return null;
        }

        $resolved = \realpath($filePath);
        if (!\is_string($resolved)) {
            return null;
        }

        $normalisedPath = \strtolower(\str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $resolved));

        if (!$codebase->file_storage_provider->has($normalisedPath)) {
            return null;
        }

        $fileStorage = $codebase->file_storage_provider->get($normalisedPath);

        // Match by line. The closure_id format is `<path>:<line>:<startPos>:-:closure`.
        // Anchor on both the `<path>:<line>:` prefix and the `:-:closure` suffix to
        // avoid accidentally matching a path that contains `:<line>:` somewhere
        // earlier (paths typically don't, but the anchors are cheap insurance).
        $candidates = [];
        $linePrefix = $normalisedPath . ':' . $line . ':';
        $closureSuffix = ':-:closure';
        foreach ($fileStorage->functions as $functionId => $functionStorage) {
            if (\str_starts_with($functionId, $linePrefix) && \str_ends_with($functionId, $closureSuffix)) {
                $candidates[] = $functionStorage;
                if (\count($candidates) > 1) {
                    // Ambiguous — bail out rather than pick wrong.
                    return null;
                }
            }
        }

        return $candidates[0] ?? null;
    }

    /**
     * Build a {@see MacroDefinition} from a Psalm {@see FunctionLikeStorage} retrieved
     * via {@see self::recoverClosureStorage()}.
     *
     * Defensive copy: Psalm's storage entries are reused across analysis passes, and
     * `FunctionLikeParameter` exposes public mutable fields (taint sinks, attribute
     * sets) that any downstream consumer might write through. Shallow-cloning each
     * parameter keeps the macro registry isolated from the primary
     * {@see FunctionLikeStorage} — pseudo-method injection in
     * {@see \Psalm\LaravelPlugin\Handlers\Magic\MacroHandler} cannot corrupt Psalm's
     * source-of-truth storage by mutating a shared instance.
     *
     * `clone` is used instead of rebuilding through the 7-argument constructor
     * because `FunctionLikeParameter` carries fields the analyser actively reads
     * that aren't constructor arguments: `out_type` (consumed by
     * {@see \Psalm\Internal\Analyzer\Statements\Expression\Call\ArgumentsAnalyzer}
     * for `@param-out` narrowing), `default_type`, `attributes`, `has_docblock_type`,
     * `description`, and several more. Constructor-only rebuilding silently dropped
     * these on the storage path. Shallow clone preserves every public field while
     * still decoupling the wrapper from Psalm's stored instance.
     *
     * `Union` types are immutable in Psalm 7, so the shared `type` / `signature_type`
     * / `out_type` references inside the cloned parameters are safe.
     *
     * @param class-string $declaringClass
     * @psalm-mutation-free
     */
    private static function buildDefinitionFromStorage(
        string $declaringClass,
        string $name,
        FunctionLikeStorage $storage,
    ): MacroDefinition {
        $params = \array_map(static fn(FunctionLikeParameter $p): FunctionLikeParameter => clone $p, $storage->params);

        return new MacroDefinition(
            declaringClass: $declaringClass,
            methodName: \strtolower($name),
            casedName: $name,
            params: $params,
            returnType: $storage->return_type ?? Type::getMixed(),
            signatureReturnType: $storage->signature_return_type,
        );
    }

    /**
     * Resolve a registered macro callable to a `\ReflectionFunctionAbstract` we
     * can extract param + return types from. Handled shapes:
     *
     * - `Closure` → `ReflectionFunction`
     * - String `'Class::method'` → `ReflectionMethod` (must be public)
     * - String `'function_name'` (`function_exists` returns true) → `ReflectionFunction`
     * - Array `[Class, 'method']` / `[$obj, 'method']` → `ReflectionMethod` (must be public)
     * - Object with `__invoke` → `ReflectionMethod` for `__invoke` (must be public)
     *
     * Returns `null` for opaque objects without `__invoke`, callable arrays whose first
     * element doesn't resolve to a class/object, or methods that fail the visibility check.
     *
     * @param class-string $declaringClass for diagnostic messages only
     * @param \Closure|non-empty-string|array{0: object|class-string, 1: non-empty-string}|object $callable
     *        See {@see self::buildDefinition()} for why this is a structural union
     *        rather than the PHP `callable` pseudo-type.
     */
    private static function reflectCallable(
        string|array|object $callable,
        string $declaringClass,
        string $name,
        Progress $progress,
    ): ?\ReflectionFunctionAbstract {
        try {
            if ($callable instanceof \Closure) {
                return new \ReflectionFunction($callable);
            }

            if (\is_string($callable) && \str_contains($callable, '::')) {
                $parts = \explode('::', $callable, 2);
                if (\count($parts) !== 2) {
                    return null;
                }

                [$cls, $method] = $parts;
                if (\class_exists($cls) && \method_exists($cls, $method)) {
                    // String `'Class::method'` callables dispatch through PHP's
                    // class-string handler, which only resolves to *static* methods.
                    // A non-static target would error at runtime.
                    return self::ifPublicStatic(new \ReflectionMethod($cls, $method));
                }

                return null;
            }

            if (\is_string($callable) && \function_exists($callable)) {
                // Plain function-name callable, e.g. `Stringable::macro('upper', 'strtoupper')`.
                // Macroable dispatches these through PHP's variable-function mechanism; the
                // function's reflected signature is what callers see.
                return new \ReflectionFunction($callable);
            }

            if (\is_array($callable)) {
                // The structural-union annotation says `[0]` and `[1]` are present and
                // typed, but `getValue()` on an unrelated `static $macros` could feed
                // through a malformed array shape (missing offset, non-string `[1]`).
                // The broadened `\Throwable` catch on this method's `try` swallows any
                // resulting `TypeError` / undefined-offset error so a single bad entry
                // cannot abort discovery for the rest.
                $target = $callable[0];
                $methodName = $callable[1];
                if (\is_string($target) && \class_exists($target) && \method_exists($target, $methodName)) {
                    // Class-string array callable `[ClassName::class, 'method']` —
                    // same constraint as the `'Class::method'` string form: the target
                    // must be a static method, otherwise PHP will error at runtime.
                    return self::ifPublicStatic(new \ReflectionMethod($target, $methodName));
                }

                if (\is_object($target) && \method_exists($target, $methodName)) {
                    // Object array callable `[$obj, 'method']` works for both static
                    // and instance methods, so only the visibility check applies.
                    return self::ifPublic(new \ReflectionMethod($target, $methodName));
                }

                return null;
            }

            if (\is_object($callable) && \method_exists($callable, '__invoke')) {
                return self::ifPublic(new \ReflectionMethod($callable, '__invoke'));
            }
        } catch (\Throwable $throwable) {
            // `ReflectionException` for missing classes/methods is the common case,
            // but we also catch `TypeError` and undefined-offset errors raised by
            // malformed `static $macros` shapes (see the array branch above), and
            // any other engine error. Mirrors the broad catch in `init()`: a single
            // bad callable should not abort the whole discovery pass.
            $progress->warning(
                "Laravel plugin: MacroRegistry could not reflect callable for {$declaringClass}::{$name}: {$throwable->getMessage()}",
            );
        }

        return null;
    }

    /**
     * Reject non-public methods. Macroable's `__call` invokes the registered callable
     * from outside the target class's scope, so a protected/private method would throw
     * `Error: Call to (protected|private) method` at runtime — synthesising a pseudo-
     * method for it would be a false positive.
     */
    private static function ifPublic(\ReflectionMethod $method): ?\ReflectionMethod
    {
        return $method->isPublic() ? $method : null;
    }

    /**
     * Reject methods that aren't both public AND static. For class-string callable
     * forms (`'Class::method'` and `[Class::class, 'method']`), PHP only dispatches
     * to static methods — calling a non-static target this way errors at runtime,
     * so the macro is unreachable.
     */
    private static function ifPublicStatic(\ReflectionMethod $method): ?\ReflectionMethod
    {
        return $method->isPublic() && $method->isStatic() ? $method : null;
    }

    /**
     * @param class-string|null $selfHostClass The method's declaring class (binds `self`,
     *                                          `parent`). Null for free functions.
     * @param class-string|null $staticHostClass The class `static` should expand to: for
     *                                            object callables, `get_class($obj)`; otherwise
     *                                            same as `$selfHostClass`. Null for free
     *                                            functions.
     */
    private static function buildParameter(
        \ReflectionParameter $reflParam,
        ?string $selfHostClass,
        ?string $staticHostClass,
    ): FunctionLikeParameter {
        $reflType = $reflParam->getType();
        $type = self::reflectionTypeToUnion($reflType, $selfHostClass, $staticHostClass);

        return new FunctionLikeParameter(
            name: $reflParam->getName(),
            by_ref: $reflParam->isPassedByReference(),
            type: $type,
            signature_type: $type,
            is_optional: $reflParam->isOptional() || $reflParam->isDefaultValueAvailable(),
            // `allowsNull()` covers both `?string` syntax and `string|null` unions, and
            // is more reliable than asking the parsed Union — Psalm's parseString of
            // `?string` does not always set the nullable flag the way we'd expect.
            is_nullable: $reflType?->allowsNull() ?? false,
            is_variadic: $reflParam->isVariadic(),
        );
    }

    /**
     * Convert PHP's reflected type to a Psalm Union, or `null` when the callable declared none.
     *
     * Uses the type's string form (`int|null`, `\App\Foo`, `?array`) as the parse input —
     * Psalm's parser accepts the same surface as PHP. When `$methodHostClass` is set, the
     * resolved string also has `self`/`static`/`parent` references expanded to concrete
     * FQCNs against that host (otherwise `Foo::method(): self` would surface as the literal
     * `self`, which Psalm reads relative to the *call site*, not the method).
     *
     * Two failure modes are anticipated:
     *
     * - `TypeParseTreeException` — genuinely un-parseable input (rare; reflection emits
     *   well-formed PHP type strings). Swallow and degrade to `null` so a single bad type
     *   does not poison the registry.
     * - `\Error` from `ProjectAnalyzer::getInstance()` — `parseString` reaches for the
     *   project analyzer when expanding union/nullable types. In a unit-test context the
     *   analyzer is not initialised, which is fine — we skip and let test-only constructions
     *   carry on. In a real Psalm run the analyzer always exists, so this branch never
     *   fires in production.
     *
     * Engine-level errors outside those two paths (TypeError, AssertionError, etc.) are
     * left to propagate so genuine Psalm bugs aren't silently masked.
     *
     * @param class-string|null $selfHostClass
     * @param class-string|null $staticHostClass
     */
    private static function reflectionTypeToUnion(
        ?\ReflectionType $type,
        ?string $selfHostClass = null,
        ?string $staticHostClass = null,
    ): ?Union {
        if (!$type instanceof \ReflectionType) {
            return null;
        }

        $typeString = (string) $type;
        if ($typeString === '') {
            return null;
        }

        if ($selfHostClass !== null) {
            $typeString = self::expandSelfStaticParent($typeString, $selfHostClass, $staticHostClass ?? $selfHostClass);
        }

        try {
            return Type::parseString($typeString);
        } catch (\Psalm\Exception\TypeParseTreeException) {
            return null;
        } catch (\Error $error) {
            // Only the specific "ProjectAnalyzer not initialised" path is benign.
            // Anything else is a real bug — re-throw.
            if (!\str_contains($error->getMessage(), 'ProjectAnalyzer')) {
                throw $error;
            }

            return null;
        }
    }

    /**
     * Replace `self`/`static`/`parent` tokens in a reflected type string with concrete
     * FQCNs. `self` and `parent` resolve against the method's *declaring* class
     * (`$selfHostClass`); `static` resolves against `$staticHostClass`, which is
     * `get_class($obj)` for object callables and `$selfHostClass` otherwise. Operates
     * on word boundaries to avoid clobbering `self` substrings inside other identifiers
     * (e.g. `Selfish`, `MyParentObserver`).
     *
     * @param class-string $selfHostClass
     * @param class-string $staticHostClass
     * @psalm-pure
     */
    private static function expandSelfStaticParent(
        string $typeString,
        string $selfHostClass,
        string $staticHostClass,
    ): string {
        $parent = null;
        try {
            $parent = (new \ReflectionClass($selfHostClass))->getParentClass();
        } catch (\ReflectionException) {
            // Leave $parent null — `parent` references stay literal and parser may reject
            // them, returning null Union, which is the same conservative outcome.
        }

        $replacements = [
            '/\bself\b/' => '\\' . $selfHostClass,
            '/\bstatic\b/' => '\\' . $staticHostClass,
        ];
        if ($parent instanceof \ReflectionClass) {
            $replacements['/\bparent\b/'] = '\\' . $parent->getName();
        }

        return \preg_replace(\array_keys($replacements), \array_values($replacements), $typeString) ?? $typeString;
    }
}
