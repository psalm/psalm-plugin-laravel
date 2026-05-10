<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Magic;

use Psalm\Internal\Analyzer\ClassLikeAnalyzer;
use Psalm\LaravelPlugin\Providers\MacroDefinition;
use Psalm\LaravelPlugin\Providers\MacroRegistry;
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
 * - **Fluent return narrowing**: a macro whose body returns `$this` should narrow
 *   to the calling instance type (`@psalm-this-out` / `self_out_type`). Out of
 *   scope for the foundation.
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
     * Discover macros from the booted Laravel app and inject them into Psalm
     * class storage as pseudo-methods, propagating to all analysed descendants.
     */
    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();

        MacroRegistry::init($codebase->progress);

        $macroableClasses = MacroRegistry::getKnownMacroableClasses();
        if ($macroableClasses === []) {
            return;
        }

        // Build a lookup keyed by lowercase FQCN (matches `parent_classes` keying).
        // Each entry is the list of MacroDefinition instances to inject for that owner.
        /** @var array<lowercase-string, list<MacroDefinition>> $injectionMap */
        $injectionMap = [];
        foreach ($macroableClasses as $fqcn) {
            $injectionMap[\strtolower($fqcn)] = \array_values(MacroRegistry::for($fqcn));
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
    private static function injectPseudoMethod(
        ClassLikeStorage $storage,
        MacroDefinition $def,
    ): void {
        $methodName = $def->methodName;

        // Block injection if a real method with this name exists anywhere in the
        // class's own storage *or its inheritance chain*. `$storage->methods` only
        // lists locally declared methods; inherited declarations (from a parent
        // that the populator already merged into this storage's view) live in
        // `declaring_method_ids`. Without checking both, a propagated macro could
        // shadow a real inherited method's signature in the static-call path,
        // mis-reporting argument-count diagnostics.
        if (
            isset($storage->methods[$methodName])
            || isset($storage->declaring_method_ids[$methodName])
        ) {
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

        return $methodStorage;
    }
}
