<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Illuminate\Contracts\Container\Container;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Psalm\LaravelPlugin\Internal\PathCaseCanonicalizer;
use Psalm\LaravelPlugin\Internal\VendorDirectory;
use Psalm\Progress\Progress;

/**
 * Compiles every Blade template of the analyzed application into a shadow PHP file and hands both
 * sides to Psalm: the shadow for analysis, the template for reporting.
 *
 * Must run synchronously inside the plugin entry point. Shadows can only join the analysis while
 * `Config::initializePlugins()` is on the stack, before Psalm starts scanning.
 *
 * Nothing here throws. Every failure disables Blade analysis for the run with one warning naming
 * the cause, because an opt-in extra is never worth failing an analysis over.
 *
 * @internal
 */
final class BladeBootstrapper
{
    /** Templates named in the aggregated skip warning before it degrades to a count. */
    private const FAILURES_TO_NAME = 3;

    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private readonly Container $app,
        private readonly ShadowRegistrar $registrar,
        private readonly Progress $output,
        private readonly string $shadowDir,
        /** Opt-in for UnusedViewData: gates two extra AST walks per template. */
        private readonly bool $collectDataIncludes = false,
        /**
         * Test seam: vendor dir for the hint-root filter. Null derives it from
         * laravel/framework's install path (the plugin's own vendor, in a unit test).
         */
        private readonly ?string $vendorDirOverride = null,
    ) {}

    /** Kept for name-ownership checks against namespaces that lost a root to the vendor filter. */
    private ?FileViewFinder $finder = null;

    /**
     * Namespaces with a hint root dropped by the vendor filter: root order no longer mirrors
     * Laravel's resolution there (the dropped root still wins), so each survivor is verified
     * against the finder. @see templateOwnsName()
     *
     * @var array<string, true>
     */
    private array $vendorShadowedNamespaces = [];

    /**
     * Buffered, not written to the registries directly: a degraded run must leave them empty
     * (#1518). Registries keep their own lowest-root-index-wins precedence.
     *
     * @var list<array{0: string, 1: int, 2: string}> view name, view root index, template path
     */
    private array $pendingTemplates = [];

    /** @var list<array{0: string, 1: int, 2: ViewDataContract, 3: array{0: list<string>, 1: bool}|null}> */
    private array $pendingContracts = [];


    /** @return bool whether shadows joined the analysis; false means Blade analysis is off for the run */
    public function boot(): bool
    {
        try {
            return $this->run();
        } catch (\Throwable $throwable) {
            $this->degrade($throwable::class . ': ' . $throwable->getMessage());

            return false;
        }
    }

    private function run(): bool
    {
        $compiler = $this->resolveCompiler();

        if (!$compiler instanceof BladeCompiler) {
            return false;
        }

        $viewPaths = $this->resolveViewPaths();

        if ($viewPaths === null) {
            return false;
        }

        // Shared with compileAll()'s findTemplates() call below: both must enumerate from the
        // SAME case-collapsed roots that registerContract()/ViewName::resolve() match against.
        $roots = $this->resolveRoots($viewPaths);

        /** @var array<string, string> $failures template path => reason */
        $failures = [];
        $templates = $this->findTemplates($roots, $failures);

        if ($templates === [] && $failures === []) {
            // Not a failure (an API-only app is normal), but worth surfacing under --no-progress:
            // the shape of #1497, a real view tree that discovered nothing.
            $this->output->warning(
                'Laravel plugin: Blade template analysis is enabled, but no Blade templates were discovered.',
            );
        }

        // An incomplete scan can hide templates still on disk; pruning is only safe once
        // discovery is known-complete, or a transient read failure deletes live shadows.
        $templatesFullyDiscovered = $failures === [];

        // A run with no templates still has to reach prune() below, or a template deleted since
        // the last run leaves its shadow and manifest entry behind forever.
        $shadowDir = $this->prepareShadowDir();

        if ($shadowDir === null) {
            $this->reportFailures($failures);

            return false;
        }

        [$environmentHash, $trustedEnvironment] = CompilerEnvironment::describe($compiler);

        if (!$trustedEnvironment) {
            $this->output->warning(
                'Laravel plugin: the Blade compiler environment (a custom directive, condition, precompiler, '
                . 'extension, or component map) could not be fully resolved, so cached Blade shadows are not '
                . 'trusted for this run; every template is recompiled.',
            );
        }

        $manifest = new ShadowManifest($shadowDir, $environmentHash);
        $manifest->load();

        $components = $this->componentViews($templates, $roots, ComponentViewMap::build($this->registrar->projectFiles()));
        $shadows = $this->compileAll(new ShadowCompiler($compiler), $manifest, $templates, $roots, $failures, $trustedEnvironment, $components);

        if ($templatesFullyDiscovered) {
            $manifest->prune($templates);
        }

        $manifest->flush();
        $this->reportFailures($failures);

        if ($shadows === []) {
            return false;
        }

        // Reached only with ≥1 shadow: a remap needs a ShadowRegistry entry.
        // Config::reportIssueInFile() consults only this project-file list.
        if (!$this->registrar->markTemplatesReportable($templates)) {
            $this->degrade(
                "issues found in Blade templates could not be made reportable (Psalm's internal project-file "
                . 'list is not writable on this Psalm version)',
            );

            return false;
        }

        // Psalm's scanner only reads the LAST stacked docblock comment on a node (see
        // ShadowRegistrar::queueClassLikesForScanning); queued once so a warm-manifest run
        // (which skips ShadowCompiler) still gets them.
        $this->registrar->queueClassLikesForScanning(PreludeBuilder::ambientClassNames());

        // #1505: a vendor directive can compile a class name into a string literal, invisible to
        // Psalm's scanner and the ambient queue above. Read off DISK, not the fresh compile
        // result, so a warm-manifest run (which skips ShadowCompiler) is covered too.
        $collector = new ClassLiteralCollector();
        $literalCandidates = [];

        foreach ($shadows as $shadowPath) {
            $shadowSource = @\file_get_contents($shadowPath);

            if ($shadowSource === false) {
                continue;
            }

            foreach ($collector->collectFromSource($shadowSource) as $candidate) {
                $literalCandidates[$candidate] = true;
            }
        }

        $this->registrar->queueResolvableClassLikesForScanning(\array_keys($literalCandidates));

        // Enqueue is the one irreversible step, done last. Remap entries publish in a `finally`:
        // a partial enqueue that then throws must not leave shadow paths unmapped.
        try {
            $this->registrar->registerShadowsForAnalysis(\array_values($shadows));
        } finally {
            $this->publishShadowEntries($manifest, $shadows);
        }

        $this->publishTemplateFacts();

        return true;
    }

    /**
     * @param array<string, string> $shadows template path => shadow path
     */
    private function publishShadowEntries(ShadowManifest $manifest, array $shadows): void
    {
        foreach ($shadows as $shadowPath) {
            $entry = $manifest->shadowEntry($shadowPath);

            if ($entry instanceof ShadowEntry) {
                ShadowRegistry::register($shadowPath, $entry);
            }
        }
    }

    /** @see self::$pendingTemplates for why this is deferred to the end of a successful run */
    private function publishTemplateFacts(): void
    {
        foreach ($this->pendingTemplates as [$viewName, $rootIndex, $templatePath]) {
            ViewReferenceRegistry::registerTemplate($viewName, $rootIndex, $templatePath);
        }

        foreach ($this->pendingContracts as [$viewName, $rootIndex, $contract, $dataIncludes]) {
            ContractRegistry::register($viewName, $rootIndex, $contract, $dataIncludes);
        }

    }

    /**
     * @param list<string>                              $templates
     * @param list<array{0: string, 1: string|null}>     $roots     resolved, deduped roots in finder
     *                                                     order, which decides which template wins a
     *                                                     view name two roots both define
     * @param array<string, string>                      $failures  template path => reason, appended to
     * @param bool                                        $trustedEnvironment when false,
     *                                                     {@see CompilerEnvironment::describe()} could
     *                                                     not resolve every compiler input, so the
     *                                                     freshness check is skipped and every template
     *                                                     recompiles this run regardless of the manifest
     * @param array<string, array{class: string, keys: list<string>, scope: string}> $components template path =>
     *                                                     the class component rendering it
     *
     * @return array<string, string> template path => shadow path
     */
    private function compileAll(
        ShadowCompiler $compiler,
        ShadowManifest $manifest,
        array $templates,
        array $roots,
        array &$failures,
        bool $trustedEnvironment,
        array $components = [],
    ): array {
        $shadows = [];
        $parser = new ContractParser();
        $collector = $this->collectDataIncludes ? new ViewReferenceCollector() : null;
        $requiredSlots = $this->collectDataIncludes ? ShadowManifest::SLOT_DATA_INCLUDES : 0;

        foreach ($templates as $template) {
            $source = @\file_get_contents($template);

            if ($source === false) {
                $failures[$template] = 'the template could not be read';
                $this->claimNameOnly($template, $roots);

                continue;
            }

            // The relocator re-reads the template later; a prefix derived from stale bytes
            // silently disables the marker strip (ShadowTarget).
            ShadowRegistry::registerMarkerPrefix($template, MarkerComment::prefixFor($source));

            $component = $components[$template] ?? null;
            // The scope statement copies render() from a PHP file, so it is part of the fingerprint.
            $preludeInputs = $component['scope'] ?? '';

            if ($trustedEnvironment && $manifest->isFresh($template, $source, $requiredSlots, $preludeInputs)) {
                $shadowPath = $manifest->shadowPathFor($template, $source, $preludeInputs);
                $shadows[$template] = $shadowPath;
                $this->registerContract(
                    $template,
                    $roots,
                    $manifest->contractFor($shadowPath),
                    $manifest->dataIncludesFor($shadowPath),
                );


                continue;
            }

            // NOT fed into compile(): contract types in the prelude would change the shadow's
            // fingerprint. Built AFTER compile so the read set comes off compiled output.
            $shadow = $compiler->compile($template, $source, [], $component);

            if ($shadow instanceof BladeCompileError) {
                $failures[$shadow->templatePath] = $shadow->message;
                $this->claimNameOnly($template, $roots);

                continue;
            }

            // The copied render() data is the component's code: its writes are not template locals.
            $templateCode = $component === null ? $shadow->contents : \str_replace($component['scope'], '', $shadow->contents);

            $contract = $this->collectDataIncludes
                ? $parser->parseDataContract($source, $templateCode)
                : $parser->parseDeclarations($source);

            // Null (not empty) when the pass is off, so isFresh() can tell "never collected"
            // from "collected nothing".
            $dataIncludes = $this->collectDataIncludes ? $collector?->collectDataIncludes($templateCode) : null;

            try {
                $shadowPath = $manifest->store($template, $source, $shadow, $contract, $dataIncludes, $preludeInputs);
                $shadows[$template] = $shadowPath;
                $this->registerContract($template, $roots, $contract, $dataIncludes);

            } catch (\RuntimeException $throwable) {
                $failures[$template] = $throwable->getMessage();
                $this->claimNameOnly($template, $roots);
            }
        }

        return $shadows;
    }


    /**
     * The template each mapped view name resolves to, with Laravel's lowest-root-wins precedence the
     * registries apply: only the template that actually renders under the name gets the component.
     *
     * @param list<string>                           $templates
     * @param list<array{0: string, 1: string|null}> $roots
     *
     * @return array<string, array{class: string, keys: list<string>, scope: string}> template path => component view
     */
    private function componentViews(array $templates, array $roots, ComponentViewMap $map): array
    {
        $owners = [];

        foreach ($templates as $template) {
            foreach (ViewName::resolve($template, $roots) as [$rootIndex, $viewName]) {
                if ($map->get($viewName) !== null
                    && (!isset($owners[$viewName]) || $rootIndex < $owners[$viewName][0])
                    && $this->templateOwnsName($viewName, $template)
                ) {
                    // The name rides along: a numeric view name comes back from the key as an int.
                    $owners[$viewName] = [$rootIndex, $template, $viewName];
                }
            }
        }

        $components = [];

        foreach ($owners as [, $template, $viewName]) {
            $components[$template] = $map->get($viewName);
        }

        return \array_filter($components);
    }

    /**
     * Realpaths, deduped by (path, namespace): a published override's directory is both the
     * default root's last segment AND its own hint root, and both names must survive. Callers
     * pre-canonicalize; this dedups by exact string only.
     *
     * @param list<array{0: string, 1: string|null}> $viewPaths
     *
     * @return list<array{0: string, 1: string|null}>
     */
    private function resolveRoots(array $viewPaths): array
    {
        $roots = [];
        $seen = [];

        foreach ($viewPaths as [$viewPath, $namespace]) {
            $resolved = \realpath($viewPath);

            if ($resolved === false) {
                continue;
            }

            // realpath() preserves a symlink's STORED target casing, which can reintroduce a
            // mis-cased spelling after canonicalization — canonicalize again.
            $resolved = PathCaseCanonicalizer::canonicalize($resolved);
            // Trimming the filesystem root would leave '', which no downstream check survives.
            $trimmed = \rtrim($resolved, \DIRECTORY_SEPARATOR);
            $resolved = $trimmed === '' ? $resolved : $trimmed;
            // "\0" never occurs in a namespace, so a null (default-root) marker cannot collide.
            $key = ($namespace ?? "\0") . "\0" . $resolved;

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $roots[] = [$resolved, $namespace];
        }

        return $roots;
    }

    /**
     * Claims a view name with no declarations for a template this pass couldn't process: Laravel
     * still renders the first root's file, so an unclaimed name would let a same-named template
     * in a later root wrongly own it.
     *
     * @param list<array{0: string, 1: string|null}> $roots
     */
    private function claimNameOnly(string $templatePath, array $roots): void
    {
        $this->registerContract($templatePath, $roots, new ViewDataContract([], false));

    }

    /**
     * Registers an empty contract too: skipping it would leave the view name unclaimed for a
     * same-named template in a LATER root to wrongly own. {@see claimNameOnly()} is the same
     * idea for a template this pass couldn't process at all.
     *
     * @param list<array{0: string, 1: string|null}> $roots
     * @param array{0: list<string>, 1: bool}|null   $dataIncludes null when the collection pass was off
     */
    private function registerContract(
        string $templatePath,
        array $roots,
        ?ViewDataContract $contract,
        ?array $dataIncludes = null,
    ): void {
        // A published override's file matches two roots (default-root name AND namespace name);
        // both must register or one becomes unresolvable.
        foreach (ViewName::resolve($templatePath, $roots) as [$rootIndex, $viewName]) {
            if (!$this->templateOwnsName($viewName, $templatePath)) {
                continue;
            }

            $this->pendingTemplates[] = [$viewName, $rootIndex, $templatePath];

            if (!$contract instanceof ViewDataContract) {
                continue;
            }

            $this->pendingContracts[] = [$viewName, $rootIndex, $contract, $dataIncludes];
        }
    }

    private function resolveCompiler(): ?BladeCompiler
    {
        if (!$this->app->bound('blade.compiler')) {
            // Normal for a package analyzed through the Testbench fallback without the view
            // service provider, and for any bootstrap that trims it.
            $this->degrade("the 'blade.compiler' service is not bound in the analyzed application");

            return null;
        }

        try {
            $compiler = $this->asCompiler($this->app->make('blade.compiler'));
        } catch (\Throwable $throwable) {
            $this->degrade("resolving the 'blade.compiler' service threw: " . $throwable->getMessage());

            return null;
        }

        if (!$compiler instanceof BladeCompiler) {
            $this->degrade("the 'blade.compiler' service is not an " . BladeCompiler::class);

            return null;
        }

        return $compiler;
    }

    /**
     * View roots paired with namespace (null = default). `getPaths()` roots first — decides which
     * template wins a shared name — then each namespace's `getHints()` roots in finder order.
     *
     * Vendor-directory hint roots are dropped so third-party template diagnostics and annotation
     * writes stay outside the analyzed project; `getPaths()` roots are never filtered.
     *
     * @return list<array{0: string, 1: string|null}>|null
     */
    private function resolveViewPaths(): ?array
    {
        $finder = $this->resolveFinder();

        if (!$finder instanceof FileViewFinder) {
            $this->degrade('the view finder could not be resolved to an ' . FileViewFinder::class);

            return null;
        }

        $roots = [];

        foreach (\array_values($finder->getPaths()) as $path) {
            $roots[] = [PathCaseCanonicalizer::canonicalize($path), null];
        }

        $vendorDir = $this->vendorDirectory();

        // FileViewFinder's own docblock says only `array<string, array>`, but addNamespace()
        // casts to a string list; narrowed here because Psalm cannot see through the cast.
        /** @psalm-var array<string, list<string>> $hints */
        $hints = $finder->getHints();

        foreach ($hints as $namespace => $hintPaths) {
            foreach ($hintPaths as $hint) {
                // Canonicalized before the vendor check: the same physical root can reach here
                // twice under different case (a published override's hint vs. its default-root
                // path, #1552), and realpath() alone won't collapse that on a case-insensitive
                // filesystem.
                $hint = PathCaseCanonicalizer::canonicalize($hint);

                if ($vendorDir !== null && $this->isUnderVendorDirectory($hint, $vendorDir)) {
                    $this->vendorShadowedNamespaces[$namespace] = true;

                    continue;
                }

                $roots[] = [$hint, $namespace];
            }
        }

        $this->finder = $finder;

        return $roots;
    }

    /**
     * Whether Laravel would actually resolve `$viewName` to `$templatePath`. Only checked for a
     * namespace that lost a hint root to the vendor filter: the dropped root can still own the
     * name there. A finder failure keeps the claim (fail open).
     */
    private function templateOwnsName(string $viewName, string $templatePath): bool
    {
        $namespace = \strstr($viewName, '::', true);

        if ($namespace === false || !isset($this->vendorShadowedNamespaces[$namespace]) || !$this->finder instanceof FileViewFinder) {
            return true;
        }

        try {
            $winner = \realpath($this->finder->find($viewName));
        } catch (\Throwable) {
            return true;
        }

        // The finder resolves against its OWN uncanonicalized hints, so its winner needs
        // canonicalizing too before comparing.
        return $winner !== false && PathCaseCanonicalizer::canonicalize($winner) === $templatePath;
    }

    /**
     * The analyzed project's Composer vendor directory, or the test override. Null when it
     * cannot be determined — the vendor filter above is then skipped. {@see VendorDirectory} for
     * why the boundary is the install root, not the substring `vendor`.
     */
    private function vendorDirectory(): ?string
    {
        $vendorDir = $this->vendorDirOverride ?? VendorDirectory::path();

        if ($vendorDir === null) {
            return null;
        }

        // Must match the hints' canonicalization (realpath-then-canonicalize) or a vendor path
        // whose spelling differs from the on-disk casing fails the prefix test against every hint.
        $resolved = \realpath($vendorDir);

        return $resolved === false ? $vendorDir : PathCaseCanonicalizer::canonicalize($resolved);
    }

    private function isUnderVendorDirectory(string $path, string $vendorDir): bool
    {
        return VendorDirectory::contains($path, $vendorDir);
    }

    /**
     * `ViewServiceProvider::registerViewFinder()` binds `'view.finder'` with `bind()`, not
     * `singleton()`: a fresh `make('view.finder')` never carries the hints `loadViewsFrom()` added
     * to the `'view'` Factory's own finder. `'view.finder'` is kept only as a fallback for a boot
     * with no Factory at all.
     */
    private function resolveFinder(): ?FileViewFinder
    {
        // Each branch catches on its own: a 'view' closure that throws under the plugin's partial
        // boot (documented boot shape) must fall through to 'view.finder', not disable the feature.
        if ($this->app->bound('view')) {
            try {
                $finder = $this->asFactoryFinder($this->app->make('view'));

                if ($finder instanceof FileViewFinder) {
                    return $finder;
                }
            } catch (\Throwable $throwable) {
                $this->output->debug('Laravel plugin: resolving the view factory threw: ' . $throwable->getMessage() . "\n");
            }
        }

        if ($this->app->bound('view.finder')) {
            try {
                return $this->asFinder($this->app->make('view.finder'));
            } catch (\Throwable $throwable) {
                $this->output->debug('Laravel plugin: resolving the view finder threw: ' . $throwable->getMessage() . "\n");
            }
        }

        return null;
    }

    /**
     * The container is documented as returning `mixed`, so each binding gets narrowed at exactly
     * one place instead of assigning `mixed` into a variable first.
     *
     * @psalm-pure
     */
    private function asCompiler(mixed $resolved): ?BladeCompiler
    {
        return $resolved instanceof BladeCompiler ? $resolved : null;
    }

    /**
     * @psalm-pure
     */
    private function asFinder(mixed $resolved): ?FileViewFinder
    {
        return $resolved instanceof FileViewFinder ? $resolved : null;
    }

    /**
     * @psalm-mutation-free
     */
    private function asFactoryFinder(mixed $resolved): ?FileViewFinder
    {
        return $resolved instanceof Factory ? $this->asFinder($resolved->getFinder()) : null;
    }

    /**
     * Every `*.blade.php` file under the view roots, realpath'd and deduplicated: a path registered
     * with Psalm has to be the same string Psalm itself would use, and view roots overlap in
     * applications that add a package path twice.
     *
     * @param list<array{0: string, 1: string|null}> $roots    resolved, deduped view roots
     * @param array<string, string>                  $failures appended to, keyed by the directory
     *                                                that failed
     *
     * @return list<string>
     */
    private function findTemplates(array $roots, array &$failures): array
    {
        $templates = [];

        foreach ($roots as [$viewPath]) {
            if (!\is_dir($viewPath)) {
                // A configured-but-absent view root (a package path, a not-yet-published vendor
                // directory) is not an error for Laravel either.
                continue;
            }

            try {
                /** @var iterable<\SplFileInfo> $files */
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($viewPath, \FilesystemIterator::SKIP_DOTS),
                );

                foreach ($files as $file) {
                    if (!$file->isFile() || !\str_ends_with($file->getFilename(), '.blade.php')) {
                        continue;
                    }

                    $real = $file->getRealPath();

                    if ($real !== false) {
                        // getRealPath() can expand a mis-cased symlink target, reintroducing a
                        // spelling the roots already collapsed — same file, two shadows.
                        $templates[PathCaseCanonicalizer::canonicalize($real)] = true;
                    }
                }
            } catch (\Throwable $throwable) {
                $failures[$viewPath] = 'the directory could not be read: ' . $throwable->getMessage();
            }
        }

        $templates = \array_keys($templates);
        \sort($templates);

        return $templates;
    }

    /**
     * The shadow directory, created if absent, as an absolute path: a relative `cacheDir` would
     * otherwise reach Psalm as a relative file path, which nothing downstream of it expects.
     */
    private function prepareShadowDir(): ?string
    {
        if (!\is_dir($this->shadowDir) && !@\mkdir($this->shadowDir, 0o777, true) && !\is_dir($this->shadowDir)) {
            $this->degrade("the shadow cache directory '{$this->shadowDir}' could not be created");

            return null;
        }

        if (!\is_writable($this->shadowDir)) {
            $this->degrade("the shadow cache directory '{$this->shadowDir}' is not writable");

            return null;
        }

        $resolved = \realpath($this->shadowDir);

        if ($resolved === false) {
            $this->degrade("the shadow cache directory '{$this->shadowDir}' could not be resolved");

            return null;
        }

        return $resolved;
    }

    /**
     * One warning for the whole run, however many templates failed: the individual causes go to
     * `--debug`, because a broken template is a per-template fact and the run-level fact is that
     * some templates are not covered.
     *
     * @param array<string, string> $failures
     */
    private function reportFailures(array $failures): void
    {
        if ($failures === []) {
            return;
        }

        foreach ($failures as $path => $reason) {
            $this->output->debug("Laravel plugin: skipped Blade template '{$path}': {$reason}\n");
        }

        $count = \count($failures);
        $named = \array_slice(\array_keys($failures), 0, self::FAILURES_TO_NAME);
        $suffix = $count > \count($named) ? ', ...' : '';

        $this->output->warning(
            "Laravel plugin: {$count} Blade template(s) were skipped and are not analyzed ("
            . \implode(', ', $named) . $suffix . '). Run with --debug for the individual causes.',
        );
    }

    private function degrade(string $cause): void
    {
        $this->output->warning('Laravel plugin: Blade template analysis is disabled for this run, because ' . $cause . '.');
    }
}
