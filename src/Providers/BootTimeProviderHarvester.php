<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers;

use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Psalm\LaravelPlugin\Util\ProviderBindingHarvester;
use Psalm\Progress\Progress;

/**
 * Boot-time enumeration of every Laravel `ServiceProvider` in scope, used to
 * populate {@see \Psalm\LaravelPlugin\Providers\ContainerBindingMapProvider}
 * via {@see ProviderBindingHarvester} before Psalm forks any scan/analysis worker.
 *
 * # Why boot-time and not scan-time?
 *
 * The original prototype lived in an `AfterClassLikeVisit` hook. Two failure
 * modes made that approach a silent no-op for the real audience of issues
 * #942 / #766:
 *
 * 1. **Worker IPC.** Psalm forks scanner workers when `pool_size > 1` and
 *    `files > 512` (see `Scanner::scanFiles`). The shutdown task that ships
 *    worker state back to the parent (`ShutdownScannerTask`) only carries
 *    Psalm-internal storage; plugin static state stays in the worker. By the
 *    time consumers read the map in the analysis phase (which runs in the
 *    parent + its own workers), the map is empty.
 * 2. **Warm cache.** `ReflectorVisitor::dispatchEvent` only fires
 *    `AfterClassLikeVisit` while the class is being freshly scanned. On any
 *    run where the file's cached storage is reused, the hook never fires and
 *    the map is empty. Psalm even ships `--no-reflection-cache` specifically
 *    as a debug switch for plugin authors hitting this.
 *
 * Running in the main process at plugin init, before `ApplicationProvider::bootApp()`
 * returns, sidesteps both: static state is set up once in the parent and inherited
 * via copy-on-write into every fork; warm cache state has no bearing because we
 * never touch a Psalm hook.
 *
 * # Provider discovery
 *
 * Three sources, all read in the main process:
 *
 * 1. **Vendor packages** — `vendor/composer/installed.json` carries
 *    `extra.laravel.providers[]` for every package that opts into Laravel's
 *    auto-discovery mechanism. This is the canonical answer to "which vendor
 *    providers does Laravel boot" and is what `Illuminate\Foundation\PackageManifest`
 *    reads at runtime. We bypass `PackageManifest` itself because it requires a
 *    booted `Application` and we want this to work even when the Testbench app
 *    is misconfigured.
 *
 *    Honours `extra.laravel.dont-discover` exactly as
 *    `PackageManifest::packagesToIgnore()` does: per-package opt-outs are
 *    merged with the root `composer.json`'s `extra.laravel.dont-discover`, and
 *    the literal `"*"` disables every package.
 * 2. **First-party providers** — `bootstrap/providers.php` (Laravel 11+) and
 *    `config/app.php`'s `providers` array (Laravel ≤10). Both files are read
 *    via PhpParser AST rather than `include`d — we never execute user code at
 *    plugin init.
 * 3. **Explicit list** — caller can pass `$extraProviders` for tests or for
 *    advanced users with non-standard provider registration.
 *
 * # Why static parsing instead of running `register()`?
 *
 * `ApplicationProvider::doGetApp()` does boot a Laravel application that runs
 * provider `register()` methods, so in principle the container already knows
 * every binding by the time the plugin starts. In practice (see issue #942)
 * provider boot regularly fails inside Testbench because vendor packages
 * depend on missing config / env / extensions. Errors are swallowed by
 * `withErrorExceptionsSuppressed`; the binding goes missing; downstream
 * `Facade::getFacadeRoot()` and `app('alias')` calls then fail with the
 * `BindingResolutionException` that the plugin currently surfaces as a warning.
 *
 * Static parsing extracts the binding intent from source — no env, no runtime —
 * so it succeeds for any provider whose source file is present, even when the
 * runtime boot would have failed.
 *
 * # Known coverage gaps (intentional, with workarounds)
 *
 * - **Laravel 11+ `$app->withProviders([...])` inline in `bootstrap/app.php`** —
 *   not parsed. Workaround: list providers in `bootstrap/providers.php` instead.
 * - **`$this->app->register(SubProvider::class)` chains** — not followed.
 *   Workaround: add the sub-provider to `bootstrap/providers.php` or
 *   `extra.laravel.providers` in its package's `composer.json`.
 * - **Trait-imported binding helpers** — `Class_::getMethods()` returns only
 *   directly-declared methods. Bindings inside a trait method invoked from a
 *   `ServiceProvider` are not harvested.
 * - **Monorepo `cwd`-bound discovery** — first-party discovery is anchored at
 *   the current working directory. Psalm's CLI `chdir`s to the project root
 *   under default `resolve_from_config_file` behaviour, so the standard case
 *   works; users running Psalm from outside the project root may miss
 *   first-party providers.
 *
 * @internal
 */
final class BootTimeProviderHarvester
{
    /**
     * Populate {@see \Psalm\LaravelPlugin\Providers\ContainerBindingMapProvider}
     * from every discoverable provider.
     *
     * @param list<class-string> $extraProviders explicit provider FQCNs to harvest in
     *     addition to the auto-discovered set, useful for tests and for custom user
     *     registration paths the discovery probe doesn't cover.
     */
    public static function harvestAll(Progress $progress, array $extraProviders = []): void
    {
        $providers = self::collectProviderFqcns($extraProviders, $progress);

        foreach ($providers as $providerFqcn) {
            self::harvestProvider($providerFqcn, $progress);
        }
    }

    /**
     * Public test/integration hook for forcing a single provider into the map.
     * Used by the type-test bootstrap to seed the fixture provider without going
     * through composer auto-discovery (which fixtures aren't part of).
     *
     * @param class-string $providerFqcn
     */
    public static function harvestProvider(string $providerFqcn, Progress $progress): void
    {
        try {
            $reflection = new \ReflectionClass($providerFqcn);
        } catch (\Throwable $throwable) {
            // Catch widened from `ReflectionException` to `Throwable` deliberately:
            // `new ReflectionClass($fqcn)` triggers autoload, and autoload can throw
            // `\Error` / `\ParseError` / `\CompileError` (PHP-version-only attributes,
            // syntax errors in vendor code, broken `use` references) — none of which
            // are subclasses of `ReflectionException`. A `\Error` would escape this
            // method, bubble through `harvestAll`, and trip the top-level Throwable
            // catch in `Plugin::__invoke`, disabling registerHandlers/registerStubs
            // for the rest of the run. A single broken vendor provider would no-op
            // the entire plugin. Per-provider catch contains that blast radius.
            //
            // Debug, not warning: stale autoloaders and half-installed packages are
            // common enough that a warning per missing provider would storm. Surfacing
            // at debug level keeps it diagnosable under `--debug` without flooding
            // normal output.
            $progress->debug("Laravel plugin: provider harvest skipped {$providerFqcn}: reflection failed: {$throwable->getMessage()}\n");
            return;
        }

        $file = $reflection->getFileName();

        if ($file === false || !\is_file($file)) {
            // Internal class or eval'd source — no file to parse. Skip silently.
            return;
        }

        $source = @\file_get_contents($file);

        if ($source === false || $source === '') {
            $progress->debug("Laravel plugin: provider harvest skipped {$providerFqcn}: cannot read {$file}\n");
            return;
        }

        try {
            $parser = (new ParserFactory())->createForNewestSupportedVersion();
            $stmts = $parser->parse($source);
        } catch (\Throwable $throwable) {
            // Warning, not debug: parse failure on already-installed vendor PHP is
            // rare (usually a PhpParser version mismatch the user can fix by
            // updating the plugin) and bounded per-file, so the storm risk is low
            // while the diagnostic value is high.
            $progress->warning("Laravel plugin: failed to parse {$file} for provider {$providerFqcn}: {$throwable->getMessage()}");
            return;
        }

        if ($stmts === null) {
            return;
        }

        // Run the same NameResolver pass Psalm runs at scan time so `Foo::class`
        // expressions inside the provider's methods carry a `resolvedName` attribute
        // that the harvester can read.
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());

        /** @var list<\PhpParser\Node> $resolved */
        $resolved = $traverser->traverse($stmts);

        $classNode = self::findClassNode($resolved, $providerFqcn);
        if (!$classNode instanceof \PhpParser\Node\Stmt\Class_) {
            // Warning, not debug: reflection said this class lives in this file and the
            // parser successfully parsed the file, yet `findClassNode` cannot find it.
            // The only plausible causes are a stale autoloader pointing at the wrong
            // file or a namespace shape (`namespace Foo { ... }` block syntax) that the
            // single-level walker in `findClassNode` doesn't handle. Both are worth
            // surfacing.
            $progress->warning("Laravel plugin: failed to find class {$providerFqcn} in {$file}");
            return;
        }

        // Walk every method, not just register(). Several real-world packages
        // (imdhemy/laravel-in-app-purchases is the cited example in #942) put their
        // bindings in private helpers called from register(): walking only register()
        // misses them. Walking every method is harmless because the harvester
        // matches only well-formed binding shapes.
        ProviderBindingHarvester::harvestClassMethods($classNode);
    }

    /**
     * @param list<class-string> $extraProviders
     * @return list<class-string>
     */
    private static function collectProviderFqcns(array $extraProviders, Progress $progress): array
    {
        $providers = $extraProviders;
        foreach (self::readVendorProviders($progress) as $fqcn) {
            $providers[] = $fqcn;
        }

        foreach (self::readFirstPartyProviders($progress) as $fqcn) {
            $providers[] = $fqcn;
        }

        // Dedupe + drop framework providers. The framework's own providers are
        // already bound by the booted Testbench app; the runtime probe in
        // AppFacadeRegistrationHandler resolves their facades fine, and their
        // accessor strings (`'cache'`, `'router'`, etc.) are already mapped by
        // FacadeMapProvider. Re-harvesting them would just churn the map for
        // no behavioural difference.
        return \array_values(\array_unique(\array_filter(
            $providers,
            static fn(string $fqcn): bool => !\str_starts_with($fqcn, 'Illuminate\\'),
        )));
    }

    /**
     * Read `vendor/composer/installed.json` and harvest every
     * `extra.laravel.providers[]` entry, honouring Laravel's
     * `extra.laravel.dont-discover` exclusion list the same way
     * `Illuminate\Foundation\PackageManifest::packagesToIgnore()` does. Without
     * this filter, a user who opted out of auto-discovery for a package would
     * still see that package's accessors in the static map, producing false
     * positives at every `app('alias')` call site.
     *
     * @return list<class-string>
     */
    private static function readVendorProviders(Progress $progress): array
    {
        $installedJsonPath = self::locateInstalledJson();

        if ($installedJsonPath === null) {
            return [];
        }

        $raw = @\file_get_contents($installedJsonPath);
        if ($raw === false || $raw === '') {
            // `is_file` upstream already proved this exists. A read failure here
            // points at a permissions problem worth surfacing — silent fallback
            // would wipe out the entire vendor channel without explanation.
            $progress->warning("Laravel plugin: cannot read {$installedJsonPath} — vendor provider discovery skipped");
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $jsonException) {
            $progress->warning("Laravel plugin: {$installedJsonPath} is not valid JSON: {$jsonException->getMessage()} — vendor provider discovery skipped");
            return [];
        }

        if (!\is_array($decoded)) {
            return [];
        }

        // installed.json shape: { "packages": [ { "name": ..., "extra": { "laravel": { "providers": [...] } } } ] }
        // Some older Composer versions write the top-level as the package list directly.
        $packages = $decoded['packages'] ?? $decoded;

        if (!\is_array($packages)) {
            return [];
        }

        $ignoredPackages = self::collectDontDiscoverPackages($packages);
        $ignoreAllPackages = isset($ignoredPackages['*']);

        /** @var list<class-string> $providers */
        $providers = [];

        foreach ($packages as $package) {
            if (!\is_array($package)) {
                continue;
            }

            /** @psalm-var mixed $packageName */
            $packageName = $package['name'] ?? null;

            if ($ignoreAllPackages
                || (\is_string($packageName) && isset($ignoredPackages[$packageName]))
            ) {
                continue;
            }

            $extra = $package['extra'] ?? null;
            if (!\is_array($extra)) {
                continue;
            }

            $laravel = $extra['laravel'] ?? null;
            if (!\is_array($laravel)) {
                continue;
            }

            $packageProviders = $laravel['providers'] ?? null;
            if (!\is_array($packageProviders)) {
                continue;
            }

            /** @var mixed $providerFqcn */
            foreach ($packageProviders as $providerFqcn) {
                if (\is_string($providerFqcn) && $providerFqcn !== '') {
                    /** @var class-string $providerFqcn */
                    $providers[] = $providerFqcn;
                }
            }
        }

        return $providers;
    }

    /**
     * Build the set of package names to exclude from auto-discovery, mirroring
     * `Illuminate\Foundation\PackageManifest::packagesToIgnore()`. Two sources are
     * merged: every per-package `extra.laravel.dont-discover` array (a package can
     * declare that ANOTHER package should not be discovered) and the root
     * `composer.json`'s `extra.laravel.dont-discover` (the canonical user-opt-out).
     *
     * The literal `"*"` is the documented Laravel idiom for "disable all
     * auto-discovery" and is honoured the same way (callers check for it as a
     * shortcut to skip every package).
     *
     * @param list<mixed>|array<string, mixed> $packages installed.json's package list
     * @return array<string, true> set of package names to skip (plus optionally `"*"`)
     */
    private static function collectDontDiscoverPackages(array $packages): array
    {
        /** @var array<string, true> $ignored */
        $ignored = [];

        foreach ($packages as $package) {
            if (!\is_array($package)) {
                continue;
            }

            $extra = $package['extra'] ?? null;
            if (!\is_array($extra)) {
                continue;
            }

            $laravel = $extra['laravel'] ?? null;
            if (!\is_array($laravel)) {
                continue;
            }

            $dontDiscover = $laravel['dont-discover'] ?? null;
            if (!\is_array($dontDiscover)) {
                continue;
            }

            /** @var mixed $entry */
            foreach ($dontDiscover as $entry) {
                if (\is_string($entry) && $entry !== '') {
                    $ignored[$entry] = true;
                }
            }
        }

        // Root composer.json's `extra.laravel.dont-discover` is the user-visible
        // opt-out and trumps per-package declarations. Without this branch, a user
        // disabling auto-discovery for a package they have installed would still
        // see that package's accessors in the static map.
        foreach (self::readRootDontDiscover() as $packageName) {
            $ignored[$packageName] = true;
        }

        return $ignored;
    }

    /**
     * Read `extra.laravel.dont-discover` from the analyzed project's root
     * `composer.json`. Returns `[]` when the file is missing, malformed, or
     * declares no opt-outs.
     *
     * @return list<string>
     */
    private static function readRootDontDiscover(): array
    {
        $cwd = \getcwd();
        if ($cwd === false) {
            return [];
        }

        $composerJsonPath = $cwd . '/composer.json';
        if (!\is_file($composerJsonPath)) {
            return [];
        }

        $raw = @\file_get_contents($composerJsonPath);
        if ($raw === false || $raw === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!\is_array($decoded)) {
            return [];
        }

        $extra = $decoded['extra'] ?? null;
        if (!\is_array($extra)) {
            return [];
        }

        $laravel = $extra['laravel'] ?? null;
        if (!\is_array($laravel)) {
            return [];
        }

        $dontDiscover = $laravel['dont-discover'] ?? null;
        if (!\is_array($dontDiscover)) {
            return [];
        }

        /** @var list<string> $names */
        $names = [];
        /** @var mixed $entry */
        foreach ($dontDiscover as $entry) {
            if (\is_string($entry) && $entry !== '') {
                $names[] = $entry;
            }
        }

        return $names;
    }

    /**
     * Resolve the path to `vendor/composer/installed.json` for the analyzed
     * project. Walks up from the current working directory looking for a
     * `vendor/composer/installed.json` — matches how Composer's own
     * `InstalledVersions::class` locates the runtime classmap.
     */
    private static function locateInstalledJson(): ?string
    {
        $cwd = \getcwd();
        if ($cwd !== false) {
            $candidate = $cwd . '/vendor/composer/installed.json';
            if (\is_file($candidate)) {
                return $candidate;
            }
        }

        // Fallback: locate via the InstalledVersions class file (Composer ships it
        // inside vendor/composer/ alongside installed.json).
        try {
            $reflection = new \ReflectionClass(\Composer\InstalledVersions::class);
            $file = $reflection->getFileName();
            if (\is_string($file)) {
                $candidate = \dirname($file) . '/installed.json';
                if (\is_file($candidate)) {
                    return $candidate;
                }
            }
        } catch (\ReflectionException) {
            // Composer autoloader not installed — extremely unlikely in a Psalm
            // project, but tolerated.
        }

        return null;
    }

    /**
     * Read first-party provider FQCNs from `bootstrap/providers.php` (Laravel 11+)
     * or fall back to `config/app.php`'s `providers` array (Laravel ≤10).
     *
     * Both files are parsed statically (PhpParser AST → `return [...]` extraction)
     * rather than `include`d. Two reasons: (a) `include` would execute the user's
     * application code at plugin-init time, which is a footgun and arguably a
     * security risk; (b) Psalm's `UnresolvableInclude` issue would fire on the
     * dynamic path. AST parsing satisfies both concerns and is sufficient because
     * both files are conventionally pure return-array files.
     *
     * @return list<class-string>
     */
    private static function readFirstPartyProviders(Progress $progress): array
    {
        // Both file paths are anchored at the current working directory. Psalm's
        // CLI `chdir`s to the project root before plugin init (Config.php
        // resolve_from_config_file), so cwd is the analyzed project's root in the
        // standard configuration. A user invoking Psalm from outside the project
        // root (rare; some monorepos do this) will see their first-party providers
        // silently missed — they can work around by adding an explicit `--config`
        // pointing at the project's psalm.xml.
        $cwd = \getcwd();
        if ($cwd === false) {
            return [];
        }

        $bootstrapPath = $cwd . '/bootstrap/providers.php';
        if (\is_file($bootstrapPath)) {
            return self::extractProvidersFromAst($bootstrapPath, [], $progress);
        }

        $configPath = $cwd . '/config/app.php';
        if (\is_file($configPath)) {
            // config/app.php returns `['app' => ..., 'providers' => [...], ...]`, so
            // descend through the `'providers'` key before reading the list.
            return self::extractProvidersFromAst($configPath, ['providers'], $progress);
        }

        return [];
    }

    /**
     * Parse a user config file as PHP, locate its top-level `return` statement,
     * descend the literal array under `$keyPath`, and extract every class-string
     * literal it contains. No code is executed; the file's runtime side effects
     * (if any) are deliberately not observed.
     *
     * @param list<string> $keyPath nested string keys to descend before treating the
     *     reached value as the provider list. `[]` means use the top-level return.
     * @return list<class-string>
     */
    private static function extractProvidersFromAst(string $path, array $keyPath, Progress $progress): array
    {
        $source = @\file_get_contents($path);
        if ($source === false || $source === '') {
            $progress->warning("Laravel plugin: cannot read provider list at {$path}");
            return [];
        }

        try {
            $parser = (new ParserFactory())->createForNewestSupportedVersion();
            $stmts = $parser->parse($source);
        } catch (\Throwable $throwable) {
            // Warning, not silent: a parse failure here loses every first-party
            // provider in one shot. The user authored this file; a broken parse is
            // also breaking their app, so surfacing it from the plugin is fair.
            $progress->warning("Laravel plugin: failed to parse provider list at {$path}: {$throwable->getMessage()}");
            return [];
        }

        if ($stmts === null) {
            return [];
        }

        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        /** @var list<\PhpParser\Node> $resolved */
        $resolved = $traverser->traverse($stmts);

        $returnedArray = self::findTopLevelReturnArray($resolved);
        if (!$returnedArray instanceof \PhpParser\Node\Expr\Array_) {
            return [];
        }

        $targetArray = self::descendArrayByKeyPath($returnedArray, $keyPath);
        if (!$targetArray instanceof \PhpParser\Node\Expr\Array_) {
            return [];
        }

        /** @var list<class-string> $providers */
        $providers = [];

        foreach ($targetArray->items as $item) {
            if (!$item instanceof ArrayItem) {
                continue;
            }

            $fqcn = self::classStringFromExpr($item->value);
            if ($fqcn !== null) {
                $providers[] = $fqcn;
            }
        }

        return $providers;
    }

    /**
     * @param list<\PhpParser\Node> $stmts
     * @psalm-mutation-free
     */
    private static function findTopLevelReturnArray(array $stmts): ?Array_
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Return_ && $stmt->expr instanceof Array_) {
                return $stmt->expr;
            }
        }

        return null;
    }

    /**
     * Descend `$array` through `$keyPath` of string keys. Returns the inner
     * `Array_` node, or null if any segment is missing or non-array.
     *
     * @param list<string> $keyPath
     * @psalm-mutation-free
     */
    private static function descendArrayByKeyPath(Array_ $array, array $keyPath): ?Array_
    {
        $current = $array;

        foreach ($keyPath as $key) {
            $next = null;
            foreach ($current->items as $item) {
                if (!$item instanceof ArrayItem || !$item->key instanceof \PhpParser\Node\Expr) {
                    continue;
                }

                if ($item->key instanceof String_ && $item->key->value === $key) {
                    if ($item->value instanceof Array_) {
                        $next = $item->value;
                    }

                    break;
                }
            }

            if (!$next instanceof \PhpParser\Node\Expr\Array_) {
                return null;
            }

            $current = $next;
        }

        return $current;
    }

    /**
     * Extract a class-string FQCN from an array-item expression. Accepts both
     * `Foo\Bar::class` (the canonical Laravel form) and a plain string literal
     * `'Foo\\Bar'` (legacy / docs-example form).
     *
     * @return ?class-string
     */
    private static function classStringFromExpr(\PhpParser\Node\Expr $expr): ?string
    {
        if ($expr instanceof ClassConstFetch
            && $expr->name instanceof Identifier
            && \strtolower($expr->name->toString()) === 'class'
            && $expr->class instanceof \PhpParser\Node\Name
        ) {
            /** @psalm-var mixed $resolved */
            $resolved = $expr->class->getAttribute('resolvedName');
            if (\is_string($resolved) && $resolved !== '') {
                /** @var class-string */
                return \ltrim($resolved, '\\');
            }

            $raw = $expr->class->toString();
            /** @var class-string */
            return \ltrim($raw, '\\');
        }

        if ($expr instanceof String_ && $expr->value !== '') {
            return $expr->value;
        }

        return null;
    }

    /**
     * Find the AST `Class_` node matching a given provider FQCN inside a parsed file.
     * Walks one level of namespaces; nested namespaces aren't a valid PHP shape so
     * a flat lookup is sufficient.
     *
     * @param list<\PhpParser\Node> $stmts
     * @psalm-mutation-free
     */
    private static function findClassNode(array $stmts, string $providerFqcn): ?Class_
    {
        $expectedShort = self::shortName($providerFqcn);

        foreach ($stmts as $stmt) {
            if ($stmt instanceof Namespace_) {
                foreach ($stmt->stmts as $inner) {
                    if ($inner instanceof Class_
                        && $inner->name instanceof \PhpParser\Node\Identifier
                        && $inner->name->toString() === $expectedShort
                    ) {
                        return $inner;
                    }
                }

                continue;
            }

            if ($stmt instanceof Class_
                && $stmt->name instanceof \PhpParser\Node\Identifier
                && $stmt->name->toString() === $expectedShort
            ) {
                return $stmt;
            }
        }

        return null;
    }

    /** @psalm-pure */
    private static function shortName(string $fqcn): string
    {
        $pos = \strrpos($fqcn, '\\');
        return $pos === false ? $fqcn : \substr($fqcn, $pos + 1);
    }
}
