<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Magic;

use Illuminate\Foundation\Providers\FoundationServiceProvider;
use Illuminate\Validation\ValidationException;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\LaravelPlugin\Stubs\FacadeMapProvider;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\MethodStorage;

/**
 * Resolves runtime-registered macros on Laravel's {@see \Illuminate\Support\Traits\Macroable}
 * (and Macroable-shaped) classes by injecting synthesised pseudo-method declarations
 * into Psalm's class storage.
 *
 * Foundation for issue #758. Strategy B (runtime reflection of `Macroable::$macros`).
 *
 * **Why pseudo-methods, not method providers.** The original sketch used
 * `MethodExistenceProviderInterface` + `MethodParamsProviderInterface` etc. but
 * registering a `params_provider` for a class — even one that returns `null` for
 * non-macro methods — perturbs Psalm's argument-count diagnostics for *real*
 * methods on the same class (regression observed against
 * `Variadic/AuditRejectionsTest.phpt` for `Stringable::trim` / `ltrim` / `rtrim`).
 *
 * Writing into `ClassLikeStorage::$pseudo_methods` and `$pseudo_static_methods` is
 * the same shape Psalm uses for `@method` annotations: methods that exist only when
 * routed through `__call`/`__callStatic`, with their own param/return types, looked
 * up by `MissingMethodCallHandler::handleMagicMethod()` (instance) and
 * `AtomicStaticCallAnalyzer` (static). Real methods on the class are unaffected.
 *
 * **Why both `pseudo_methods` AND `pseudo_static_methods`.** `Macroable` defines
 * BOTH `__call` and `__callStatic`, so every registered macro is callable both as
 * `$instance->macroName()` and as `Class::macroName()`. Psalm's static-call analyzer
 * reads `pseudo_static_methods` only; the instance-call analyzer reads
 * `pseudo_methods` only. Each macro gets two `MethodStorage` instances — one with
 * `is_static = false` for the instance array, one with `is_static = true` for the
 * static array. The dominant Laravel usage (e.g. `Str::macroName(...)`,
 * `Route::macroName(...)`) needs the static side.
 *
 * **Why subclass propagation.** Psalm's `Populator` copies parent `pseudo_methods`
 * into children, but it runs in `populateClassLikeStorages` which strictly precedes
 * `dispatchAfterCodebasePopulated`. Pseudo-methods we inject here are too late for
 * that copy. At lookup time, `MissingMethodCallHandler::findPseudoMethodAndClass-
 * Storages()` walks `class_implements` and `namedMixins`, NOT `parent_classes` —
 * so children would not see parent macros either. We propagate explicitly: for
 * each Macroable owner, walk every analysed class whose `parent_classes` includes
 * it and inject the same pseudo-methods.
 *
 * **Why facade propagation.** Macros are typically called through a Laravel facade
 * (`Route::ohDearWebhooks()`, `Http::getDnsRecord()`), and facade classes do NOT
 * inherit from the Macroable owner — the facade pattern delegates via `__callStatic`
 * + the container, not class inheritance. The `parent_classes` walk above therefore
 * never reaches a facade. We consult {@see FacadeMapProvider::getFacadeClasses()}
 * (built at plugin init from Laravel's `AliasLoader` + `Facade::getFacadeRoot()`)
 * and inject the Macroable owner's macros onto every facade class that proxies to
 * it, including the global alias name (`\Route`) that {@see AliasStubProvider} maps to
 * the facade with `class_alias()`.
 *
 * Scope: this pass covers every facade whose accessor resolves to a class that
 * itself uses (or shape-matches) `Macroable`. Examples that work today:
 *
 * - `Route` → `\Illuminate\Routing\Router` (Macroable)
 * - `Http` → `\Illuminate\Http\Client\Factory` (Macroable)
 * - `DB`   → `\Illuminate\Database\DatabaseManager` (Macroable)
 *
 * It also covers the *manager-forwarding* facades whose `getFacadeRoot()`
 * returns a non-Macroable manager that delegates `__call` to a per-store
 * concrete which uses `Macroable` — `Auth` → `SessionGuard`/`RequestGuard`/
 * `TokenGuard`, `Cache` → `Repository`, `Session` → `Store`, `Storage` →
 * `FilesystemAdapter`, `Mail` → `Mailer`. The seeding for those edges lives
 * in {@see FacadeMapProvider::MULTI_TARGET_FACADES} so this propagation pass
 * picks them up unchanged. See issue #899 idea #4.
 *
 * `@mixin` propagation was tried in an earlier iteration (write Builder macros
 * onto every Model declared as `@mixin Builder<static>`) but removed for two
 * reasons: (1) Psalm's static-call analyzer redirects through `handleRegular-
 * Mixins` to the mixin class itself rather than consulting the host's pseudo-
 * methods, so the writes did not actually resolve `User::macroName()` calls;
 * (2) injecting pseudo-methods onto the host risks expanding native
 * `self`/`static` types in the macro signature against the host class instead
 * of the macro's defining class. Issue #648's relation-chain pattern is handled
 * by the `parent_classes` walk above — `$user->posts()->active()` resolves
 * because `Builder<User>` is the direct dispatch target.
 *
 * TODO Strategy C / follow-up:
 * - **Docblock-aware closure types** (issue #899 idea #1): IMPLEMENTED for closures
 *   whose source Psalm has scanned. {@see MacroRegistry::recoverClosureStorage()}
 *   looks up the closure in Psalm's pre-scanned {@see \Psalm\Storage\FunctionLikeStorage}
 *   by file + line and lifts docblock-merged `@return` / `@param` types into the
 *   pseudo-method. Coverage: autoloader files
 *   ({@see \Psalm\Config::collectPredefinedFunctions} routes them through
 *   `addFileToDeepScan`), projectFiles, and stubs. Out of scope: vendor closures
 *   when those paths sit outside `<projectFiles>`, eval'd code, and the
 *   Testbench-fallback case where the analysed package's own provider is never
 *   booted (covered by the AST-scan follow-up below).
 * - **AST scan** (Strategy C proper): cover macros registered in the analysed
 *   package's own provider when running on a package without `bootstrap/app.php`
 *   (Testbench fallback path — issue #766). Walk every literal
 *   `<Class>::macro('name', $callable)` and `<Class>::mixin(...)` call in the
 *   analysed codebase, extract docblock-aware param/return types from Psalm's
 *   already-resolved AST nodes, and merge into {@see MacroRegistry}.
 * - **Eloquent local builder macros** (`$builder->macro('foo', ...)`): registered into
 *   the per-instance `$localMacros` property, NOT the static `$macros` registry uses.
 *   Static reflection cannot reach them because they only exist on a specific Builder
 *   instance. Strategy C's AST scan can pick up the literal `$builder->macro('name', ...)`
 *   call sites; deferred.
 * - **`@mixin` static-call propagation**: writing macros from a mixin onto the host
 *   class did not actually resolve `Host::macroName()` calls (Psalm's static analyzer
 *   redirects through the mixin path before consulting the host's pseudo-methods),
 *   and risks `self`/`static` mis-expansion in the macro signature. Tracked as a
 *   follow-up — needs a Psalm-side change or a different lookup hook.
 * - **Fluent return narrowing**: a closure registered as a macro with an
 *   explicit `: static` (or `: $this`) native return type ALREADY narrows to
 *   the calling instance type. `MacroRegistry` preserves the `static` token in
 *   the parsed return type for Closure callables (Macroable rebinds the
 *   closure via `bindTo($this, static::class)` so the binding semantics
 *   match), and `MissingMethodCallHandler::handleMagicMethod()` runs the
 *   pseudo-method's `return_type` through `TypeExpander::expandUnion` with the
 *   lhs caller as `$static_class_type`. Locked in by
 *   `tests/Type/tests/Macros/MacroFluentStaticTest.phpt`. What is still out of
 *   scope: closures with NO native return type whose body is structurally
 *   `return $this;`, and `@return static` / `@psalm-return $this` docblock-only
 *   annotations. Both require Strategy C (AST scan) to reach inside the
 *   closure. `MethodStorage::$self_out_type` would be the natural plumbing
 *   point but is NOT consulted on pseudo-method dispatch in Psalm 7 (read only
 *   by the real-method dispatch path — `ExistingAtomicMethodCallAnalyzer` and
 *   `NewAnalyzer`), so it cannot substitute for the `return_type`-driven
 *   narrowing path here.
 * - **Memory footprint**: each propagated macro materialises two `MethodStorage`
 *   instances on every descendant class (one per `pseudo_methods` array). Scales
 *   roughly as `descendants × macros × 2`. Acceptable for the foundation's typical
 *   load (Builder + Stringable + a handful of others); a future optimisation could
 *   share storage instances or defer materialisation behind a hook.
 * - **Curated stubs for high-value framework macros**: `Request::validate()` is
 *   registered by `FoundationServiceProvider` with no return type, so the registry
 *   surfaces it as `mixed`. A hand-authored `@method` declaration on the Request
 *   stub would give a precise return shape. The handler skips names already
 *   present in `pseudo_methods`, so the two layers compose without conflict.
 *
 * @internal
 */
final class MacroHandler implements AfterCodebasePopulatedInterface
{
    /**
     * `@throws` map for framework-registered macros whose closure body is known to raise a specific
     * exception. Two-level lookup: declaring class (as the case-preserving FQCN) → macro name
     * (lowercased per {@see MacroDefinition::$methodName}'s contract) → exception FQCN.
     *
     * The class-level scoping is load-bearing. A user registering an unrelated `validate` macro on a
     * different Macroable host (e.g. `Stringable::macro('validate', ...)`) must NOT receive
     * `ValidationException` in the synthesised throws set, or an unscoped map would surface
     * `MissingThrowsDocblock` on innocent third-party macros that happen to share a name.
     *
     * Covers the two `Request` macros registered by
     * `Illuminate\Foundation\Providers\FoundationServiceProvider::registerRequestValidation()`, both of
     * which throw `ValidationException`. With `checkForThrowsDocblock="true"` an uncaught
     * `$request->validate(...)` therefore reports `MissingThrowsDocblock`, like a real method with a
     * `@throws` tag: Psalm merges a pseudo-method's `MethodStorage::$throws` into the caller's possibly
     * thrown exceptions on the magic-call dispatch path (vimeo/psalm#11842, fixed in 7.2.0). The map is
     * applied by {@see self::buildMethodStorage()} for injected pseudo-methods and by
     * {@see self::attachFrameworkThrows()} for the `@method` tags Laravel already declares.
     *
     * **Adding new entries.** Cross-reference the closure body in Laravel's source. If the exception
     * class is not already referenced from analysed code or stubs (`ValidationException` always is, via
     * the existing validation paths), consider whether Psalm's scanner needs to be told about it:
     * `FunctionLikeDocblockScanner` queues `@throws` classes for scanning, and this map does not.
     *
     * @var array<class-string, array<lowercase-string, class-string<\Throwable>>>
     */
    private const FRAMEWORK_THROWS = [
        \Illuminate\Http\Request::class => [
            'validate' => ValidationException::class,
            'validatewithbag' => ValidationException::class,
        ],
    ];

    /**
     * Discover macros from the booted Laravel app and inject them into Psalm
     * class storage as pseudo-methods, propagating to all analysed descendants.
     */
    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();

        MacroRegistry::init($codebase->progress, $codebase);

        $macroableClasses = MacroRegistry::getKnownMacroableClasses();
        if ($macroableClasses === []) {
            return;
        }

        // Build a lookup keyed by lowercase FQCN (matches `parent_classes` keying).
        // Each entry is the list of MacroDefinition instances to inject for that owner.
        //
        // For every Macroable owner, also seed the map under each facade class that
        // proxies to it — see the "Why facade propagation" section on the class
        // docblock for the rationale. `FacadeMapProvider::getFacadeClasses()` returns
        // both the canonical facade FQCN and the global alias name, so both
        // forms (`Illuminate\Support\Facades\Route::foo()` and `\Route::foo()`) are
        // covered without depending on the `parent_classes` walk below. The alias name is
        // seeded on purpose: it is a real class when the project scans a laravel-ide-helper
        // declaration of it.
        /** @var array<lowercase-string, list<MacroDefinition>> $injectionMap */
        $injectionMap = [];
        foreach ($macroableClasses as $fqcn) {
            $defs = \array_values(MacroRegistry::for($fqcn));
            $injectionMap[\strtolower($fqcn)] = $defs;

            foreach (FacadeMapProvider::getFacadeClasses($fqcn) as $facadeClass) {
                $facadeKey = \strtolower($facadeClass);
                // Merge (not assign) in case a future facade map ever points two
                // Macroable owners at the same facade key; `injectPseudoMethod()`
                // dedupes by name.
                $injectionMap[$facadeKey] = \array_merge($injectionMap[$facadeKey] ?? [], $defs);
            }
        }

        // Walk every class Psalm has scanned. For each, gather the union of macros
        // owed to it (its own entry, plus the entry of every Macroable ancestor in
        // its `parent_classes` chain), then inject in one pass. `@mixin` targets are
        // intentionally NOT walked here — see the class docblock.
        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            $owedMacros = $injectionMap[\strtolower($storage->name)] ?? [];

            foreach ($storage->parent_classes as $parentLc => $_) {
                if (isset($injectionMap[$parentLc])) {
                    foreach ($injectionMap[$parentLc] as $def) {
                        $owedMacros[] = $def;
                    }
                }
            }

            if ($owedMacros === []) {
                continue;
            }

            foreach ($owedMacros as $def) {
                self::injectPseudoMethod($storage, $def);
            }
        }

        self::attachFrameworkThrows($codebase);
    }

    /**
     * Put {@see self::FRAMEWORK_THROWS} on the pseudo-methods that already exist for those macros.
     *
     * Laravel declares `Request::validate()` / `validateWithBag()` as class-level `@method` tags, and
     * {@see self::injectPseudoMethod()} never overwrites an existing pseudo-method, so the map would
     * otherwise only reach macros without such a tag. A `@method` tag has no `@throws` slot, hence the
     * storage write. Psalm's `Populator` shares each `MethodStorage` object with the descendants, so one
     * write covers `FormRequest` and user request classes. Only macros the booted app really registers
     * are touched.
     */
    private static function attachFrameworkThrows(Codebase $codebase): void
    {
        foreach (self::FRAMEWORK_THROWS as $class => $throwsByMethod) {
            try {
                $storage = $codebase->classlike_storage_provider->get($class);
            } catch (\InvalidArgumentException) {
                continue;
            }

            foreach ($throwsByMethod as $methodName => $exception) {
                $pseudoMethod = $storage->pseudo_methods[$methodName] ?? null;

                if ($pseudoMethod instanceof MethodStorage && self::isFrameworkMacro($class, $methodName)) {
                    $pseudoMethod->throws[$exception] = true;
                }
            }
        }
    }

    /**
     * Whether the macro registered under this name is the one Laravel's `FoundationServiceProvider` installs.
     * An app or package replacing it (`Request::macro('validate', ...)`) has no `ValidationException`
     * guarantee, so the framework throws must not follow it. A macro that is not registered is not Laravel's.
     * The closure must start inside `FoundationServiceProvider::registerRequestValidation()`: its other macro
     * closures (`hasValidRelativeSignature`, ...) live in the same file but do not throw.
     *
     * Known gap: a Request subclass that redeclares its own `static $macros` and registers a non-throwing
     * `validate` still gets the framework throws, because Psalm shares the pseudo-method storage down the
     * hierarchy.
     *
     * @param class-string $class
     * @param lowercase-string $methodName
     */
    private static function isFrameworkMacro(string $class, string $methodName): bool
    {
        try {
            /** @var array<string, object|string|array<array-key, mixed>> $registered */
            $registered = (new \ReflectionProperty($class, 'macros'))->getValue();
            $macro = \array_change_key_case($registered, \CASE_LOWER)[$methodName] ?? null;

            if (!$macro instanceof \Closure) {
                return false;
            }

            $closure = new \ReflectionFunction($macro);
            $registrar = new \ReflectionMethod(FoundationServiceProvider::class, 'registerRequestValidation');

            return $closure->getFileName() === $registrar->getFileName()
                && $closure->getStartLine() >= $registrar->getStartLine()
                && $closure->getStartLine() <= $registrar->getEndLine();
        } catch (\ReflectionException) {
            return false;
        }
    }

    /**
     * Inject a single macro as both an instance and a static pseudo-method on the
     * given class storage.
     *
     * Skips when a real method already declares the same name (PHP method
     * resolution wins over `__call`, so the macro would be unreachable at
     * runtime — and overriding the real signature would mask legitimate
     * argument-count diagnostics).
     *
     * Also skips per-array when an existing pseudo-method is already present —
     * `@method` annotations and earlier macros in the propagation chain take
     * precedence.
     */
    private static function injectPseudoMethod(ClassLikeStorage $storage, MacroDefinition $def): void
    {
        $methodName = $def->methodName;

        // Block injection if a real method with this name exists anywhere in the
        // class's own storage *or its inheritance chain*. `$storage->methods` only
        // lists locally declared methods; inherited declarations (from a parent
        // that the populator already merged into this storage's view) live in
        // `declaring_method_ids`. Without checking both, a propagated macro could
        // shadow a real inherited method's signature in the static-call path,
        // mis-reporting argument-count diagnostics.
        if (isset($storage->methods[$methodName]) || isset($storage->declaring_method_ids[$methodName])) {
            return;
        }

        if (!isset($storage->pseudo_methods[$methodName])) {
            $storage->pseudo_methods[$methodName] = self::buildMethodStorage($def, isStatic: false);
        }

        if (!isset($storage->pseudo_static_methods[$methodName])) {
            $storage->pseudo_static_methods[$methodName] = self::buildMethodStorage($def, isStatic: true);
        }
    }

    private static function buildMethodStorage(MacroDefinition $def, bool $isStatic): MethodStorage
    {
        $methodStorage = new MethodStorage();
        $methodStorage->cased_name = $def->casedName;
        // Psalm convention: `defining_fqcln` is the *lowercased* FQCN. Several Psalm
        // internal lookups assume that.
        $methodStorage->defining_fqcln = \strtolower($def->declaringClass);
        $methodStorage->is_static = $isStatic;
        $methodStorage->visibility = ClassLikeAnalyzer::VISIBILITY_PUBLIC;
        // `params` carries `@psalm-readonly-allow-private-mutation`; setParams() is
        // the only sanctioned mutation entry point.
        $methodStorage->setParams($def->params);
        $methodStorage->return_type = $def->returnType;
        // `signature_return_type` stays null when the closure had no native PHP return
        // type — Psalm's convention. The `mixed` fallback only applies to `return_type`,
        // which is the type analyzers actually consume.
        $methodStorage->signature_return_type = $def->signatureReturnType;
        $methodStorage->stubbed = true;

        // Apply the framework throws map (see {@see self::FRAMEWORK_THROWS} for shape and scope).
        $exception = self::FRAMEWORK_THROWS[$def->declaringClass][$def->methodName] ?? null;
        if ($exception !== null && self::isFrameworkMacro($def->declaringClass, $def->methodName)) {
            $methodStorage->throws[$exception] = true;
        }

        return $methodStorage;
    }
}
