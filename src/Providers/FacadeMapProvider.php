<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers;

use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\Facades\Facade;

/**
 * Maps Laravel service classes to their facade and root alias class names.
 *
 * Psalm's MethodReturnTypeProvider uses exact class name matching — a handler
 * registered for Factory::class won't fire when View::make() is called through
 * the facade. This map lets handlers discover which facade/alias classes proxy
 * to their service class so they can register for those names too.
 *
 * Seeded at plugin init from two sources:
 *
 *  1. The booted app's `AliasLoader` + `Facade::getFacadeRoot()` — every facade
 *     resolves to its *direct* root and the entry lands under that root's FQCN.
 *  2. A hardcoded multi-target edge set ({@see self::MULTI_TARGET_FACADES}) for
 *     the facades whose `getFacadeRoot()` returns a *manager* that forwards
 *     unknown method calls via `__call` to a per-store concrete. Without the
 *     multi-target seed, Macroable concretes like `SessionGuard` (Auth's
 *     runtime forwarding target) would never be linked back to the `Auth`
 *     facade, so macros registered on the concrete remain invisible at facade
 *     call sites. See issue #899 idea #4.
 *
 * Consumed at analysis time by handlers that call {@see self::getFacadeClasses()}.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/591
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/899
 */
final class FacadeMapProvider
{
    /**
     * Per-store Macroable concrete → list of facade classes (canonical FQCN + global
     * alias name) whose backing manager forwards `__call` to that concrete.
     *
     * **Why a hardcoded list.** The manager forwards at runtime via container state
     * (`AuthManager::guard()`, `CacheManager::store()`, etc.). Static reflection
     * cannot tell us which concrete a given call will be forwarded to without
     * inspecting the active container bindings, so we hardcode every forwarding
     * target Laravel ships with.
     *
     * **Why it is safe to attribute macros from every concrete to one facade.**
     * Runtime dispatch picks the concrete based on the bound guard / store /
     * mailer (e.g. `Auth::someMacro()` runs on whichever guard is current); a
     * user does not care which concrete registered the macro, only that the
     * facade exposes it. Static analysis just needs the method to exist on the
     * facade — false positives here are inherent to the multi-store pattern,
     * not to the seeding strategy.
     *
     * **When to add a new entry.** A new facade qualifies for this list when
     * (a) `Facade::getFacadeRoot()` (NOT `getFacadeAccessor()`, which returns
     * the binding-name string) resolves to an instance whose class does NOT
     * compose the `Macroable` trait, (b) that class implements `__call` to
     * forward to a runtime-selected driver / store, and (c) at least one of
     * the possible drivers / stores DOES compose `Macroable`. Verify each
     * entry by grepping `use Macroable` against `vendor/laravel/framework` —
     * Laravel can shuffle trait composition between minor versions, and if
     * the manager itself ever gains `Macroable` the single-target seed
     * already covers the facade and the multi-target entry can be removed.
     *
     * Values are listed as `[canonical-FQCN, global-alias-name]` so seeding
     * matches the shape of the existing AliasLoader walk: a Macroable owner
     * gets its pseudo-methods injected on both forms. The alias is a plain
     * string. Its `class <alias> extends <FQCN> {}` stub is only materialised
     * by {@see AliasStubProvider} when the user app actually registers that
     * alias (modern Laravel apps without an `aliases` array in
     * `config/app.php` will have only the facade FQCN). The inner `init()`
     * loop skips the alias silently when the user has not registered it.
     *
     * @var array<class-string, list<class-string|non-empty-string>>
     */
    private const MULTI_TARGET_FACADES = [
        \Illuminate\Auth\SessionGuard::class => [\Illuminate\Support\Facades\Auth::class, 'Auth'],
        \Illuminate\Auth\RequestGuard::class => [\Illuminate\Support\Facades\Auth::class, 'Auth'],
        // TokenGuard composes `use GuardHelpers, Macroable` identically to
        // SessionGuard / RequestGuard and is exposed by `AuthManager::createTokenDriver()`
        // as the built-in `'token'` driver for `config/auth.php`. Macros registered on
        // it must reach the `Auth` facade the same way the session/request-guard ones do.
        \Illuminate\Auth\TokenGuard::class => [\Illuminate\Support\Facades\Auth::class, 'Auth'],
        \Illuminate\Cache\Repository::class => [\Illuminate\Support\Facades\Cache::class, 'Cache'],
        \Illuminate\Session\Store::class => [\Illuminate\Support\Facades\Session::class, 'Session'],
        \Illuminate\Filesystem\FilesystemAdapter::class => [\Illuminate\Support\Facades\Storage::class, 'Storage'],
        \Illuminate\Mail\Mailer::class => [\Illuminate\Support\Facades\Mail::class, 'Mail'],
    ];

    /** @var array<lowercase-string, list<class-string>> service class → facade + alias classes */
    private static array $serviceToFacades = [];

    /**
     * Build the map from the booted Laravel app's alias registry.
     *
     * Must be called after ApplicationProvider::bootApp() — facades need
     * the container to resolve their underlying service class.
     */
    public static function init(\Psalm\Progress\Progress $progress): void
    {
        self::$serviceToFacades = [];

        /** @var array<string, class-string> $aliases */
        $aliases = AliasLoader::getInstance()->getAliases();

        foreach ($aliases as $alias => $facadeClass) {
            try {
                // is_subclass_of() invokes the autoloader for unknown classes.
                // For entries in AliasLoader's registry, that routes back through
                // AliasLoader::load(), which calls class_alias($target, $alias).
                //
                // Some published packages ship a misconfigured self-referential
                // entry: mateffy/laravel-introspect declares
                //   "aliases": { "Introspect": "Introspect" }
                // in composer.json (extra.laravel.aliases). Laravel's package
                // discovery registers this verbatim, so AliasLoader tries
                // class_alias('Introspect', 'Introspect'). No real class named
                // 'Introspect' exists in the global namespace (the actual Facade
                // is Mateffy\Introspect\Facades\Introspect), so PHP emits a
                // "Class not found" warning.
                //
                // Under Psalm, that warning is promoted to a RuntimeException by
                // Psalm\Internal\ErrorHandler, which without this guard propagates
                // out of __invoke() and disables the plugin for the whole run.
                // Catching it lets the iteration skip the broken entry and
                // continue mapping the remaining (valid) facades. See issue #745.
                if (!\is_subclass_of($facadeClass, Facade::class)) {
                    continue;
                }

                $root = $facadeClass::getFacadeRoot();
            } catch (\Throwable $e) {
                // Catches both:
                //  - errors raised while autoloading $facadeClass above (broken
                //    aliases, parse errors in the target file, missing parents)
                //  - BindingResolutionException or \Error from getFacadeRoot()
                //    when the facade's container binding is absent or depends on
                //    optional packages (e.g. Symfony components not installed).
                $progress->debug("Laravel plugin: FacadeMapProvider skipped {$facadeClass}: {$e->getMessage()}\n");
                continue;
            }

            // getFacadeRoot() returns mixed — container bindings can resolve to
            // scalars, null, or objects. Only objects have class names to map.
            if (!\is_object($root)) {
                continue;
            }

            $serviceClass = \get_class($root);
            /** @var lowercase-string $key */
            $key = \strtolower($serviceClass);

            /** @var class-string $facadeClass */
            self::$serviceToFacades[$key][] = $facadeClass;

            // Root aliases (e.g., 'View') are generated as stub classes that extend
            // the facade FQCN. Psalm treats them as separate classes, so handlers
            // must register for both.
            if (!\str_contains($alias, '\\')) {
                /** @var class-string $alias */
                self::$serviceToFacades[$key][] = $alias;
            }
        }

        // Seed the multi-target edge set (issue #899 idea #4). The AliasLoader walk
        // above only links each facade to its `getFacadeRoot()` target — for facades
        // whose root is a non-Macroable manager (Auth → AuthManager, Cache →
        // CacheManager, etc.) the propagation pass that consumes this map never
        // reaches the per-store concrete (`SessionGuard`, `Repository`, ...) where
        // macros actually live. See {@see self::MULTI_TARGET_FACADES} for the full
        // rationale, the curated edge list, and the "when to extend" criteria.
        foreach (self::MULTI_TARGET_FACADES as $concrete => $facadeClasses) {
            // `class_exists()` autoloads via Composer's PSR-4. The concretes named
            // here ship with their owning Laravel package (illuminate/auth,
            // illuminate/cache, ...). If a user's project trims one of those
            // packages, the lookup returns false — a normal, expected outcome
            // that stays at `debug` verbosity. If `class_exists` actually
            // *throws* (parse error, broken autoloader, custom Composer hook),
            // that is anomalous on a known-shipped framework class and is
            // raised to `warning` so users see why their macros stopped
            // resolving through the facade. Same split is applied to facade
            // lookups below.
            try {
                if (!\class_exists($concrete)) {
                    $progress->debug(
                        "Laravel plugin: FacadeMapProvider skipped multi-target concrete {$concrete}: class not found\n",
                    );
                    continue;
                }
            } catch (\Throwable $e) {
                $progress->warning(
                    "Laravel plugin: FacadeMapProvider could not load multi-target concrete {$concrete}: {$e->getMessage()}",
                );
                continue;
            }

            /** @var lowercase-string $concreteKey */
            $concreteKey = \strtolower($concrete);

            foreach ($facadeClasses as $index => $facadeClass) {
                // The first entry per row is the canonical facade FQCN that
                // ships in illuminate/support — missing only when illuminate/*
                // itself is broken. Worth a warning if absent. Subsequent
                // entries are global aliases (`Auth`, `Cache`, ...) that the
                // user may legitimately have disabled by trimming
                // `config/app.php`'s `aliases` array; missing aliases stay at
                // `debug` to avoid noise on modern Laravel installs that ship
                // without the alias array.
                $isCanonicalFqcn = $index === 0;

                try {
                    if (!\class_exists($facadeClass)) {
                        $message = "Laravel plugin: FacadeMapProvider skipped multi-target facade {$facadeClass}: class not found\n";
                        if ($isCanonicalFqcn) {
                            $progress->warning(\rtrim($message));
                        } else {
                            $progress->debug($message);
                        }

                        continue;
                    }
                } catch (\Throwable $e) {
                    $progress->warning(
                        "Laravel plugin: FacadeMapProvider could not load multi-target facade {$facadeClass}: {$e->getMessage()}",
                    );
                    continue;
                }

                /** @var class-string $facadeClass */
                self::$serviceToFacades[$concreteKey][] = $facadeClass;
            }
        }
    }

    /**
     * Get all facade and root alias class names that proxy to the given service class.
     *
     * Example: getFacadeClasses(Factory::class) returns
     * ['Illuminate\Support\Facades\View', 'View']
     *
     * @param class-string $serviceClass
     * @return list<class-string>
     * @psalm-external-mutation-free
     */
    public static function getFacadeClasses(string $serviceClass): array
    {
        return self::$serviceToFacades[\strtolower($serviceClass)] ?? [];
    }
}
