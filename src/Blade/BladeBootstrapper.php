<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Illuminate\Contracts\Container\Container;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
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

    public function __construct(
        private readonly Container $app,
        private readonly ShadowRegistrar $registrar,
        private readonly Progress $output,
        private readonly string $shadowDir,
        /**
         * Opt-in for UnusedView (`reportUnusedViews`). Gates the compile-time reference collection
         * pass: off by default so the AST walk and the manifest's references slot are pure cost paid
         * only by projects that turned the rule on.
         */
        private readonly bool $collectViewReferences = false,
        /**
         * Opt-in for UnusedViewData (`reportUnusedViewData`). Gates both the read-set extraction and
         * the data-include collection that closes it over the `@include` chain: two extra AST walks
         * per template, paid only by projects that turned the rule on.
         */
        private readonly bool $collectDataIncludes = false,
        /**
         * Test seam: the Composer vendor directory used by the hint-root filter. Null derives it
         * from laravel/framework's install path, which in a unit test is the plugin's own vendor.
         */
        private readonly ?string $vendorDirOverride = null,
    ) {}

    /**
     * The finder the roots came from, kept for name-ownership checks against namespaces that lost
     * a root to the vendor filter. Set by {@see resolveViewPaths()}, once per run instance.
     */
    private ?FileViewFinder $finder = null;

    /**
     * Namespaces with at least one hint root dropped by the vendor filter. For these, root order
     * alone no longer mirrors Laravel's resolution — the dropped root still wins names in Laravel —
     * so each surviving root's claim is verified against the finder. @see templateOwnsName()
     *
     * @var array<string, true>
     */
    private array $vendorShadowedNamespaces = [];

    /**
     * Template facts collected while compiling, published only once activation has succeeded
     * (#1518). Both registries they feed CREATE issues — contract violations at call sites,
     * UnusedView on the templates — so a run that degrades has to leave them empty, or a feature
     * that announced itself disabled keeps reporting. Insertion order is preserved and the
     * registries keep their own lowest-root-index-wins precedence.
     *
     * @var list<array{0: string, 1: int, 2: string}> view name, view root index, template path
     */
    private array $pendingTemplates = [];

    /** @var list<array{0: string, 1: int, 2: ViewDataContract, 3: array{0: list<string>, 1: bool}|null}> */
    private array $pendingContracts = [];

    /** @var list<string> referenced view names */
    private array $pendingReferences = [];

    private bool $pendingDynamic = false;

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

        /** @var array<string, string> $failures template path => reason */
        $failures = [];
        $templates = $this->findTemplates($viewPaths, $failures);

        if ($templates === [] && $failures === []) {
            // Not a failure (an API-only app or a package with no views is normal), but silent under
            // --no-progress: worth stating because it is the shape of #1497 — a real view tree the
            // plugin nonetheless discovered nothing from.
            $this->output->warning(
                'Laravel plugin: Blade template analysis is enabled, but no Blade templates were discovered.',
            );
        }

        // A view root that failed to scan can hide templates that still exist on disk; pruning
        // against an incomplete list would delete their shadows for nothing more than a
        // transient read failure, so pruning is only safe once discovery is known-complete.
        $templatesFullyDiscovered = $failures === [];

        // No templates is the normal state of a package or an API-only application, not a
        // failure, but it still has to reach prune() below: a template deleted since the
        // previous run leaves its shadow and manifest entry behind otherwise, permanently,
        // since a run with no templates is exactly the run that would never come back to
        // clean them up.
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

        $shadows = $this->compileAll(new ShadowCompiler($compiler), $manifest, $templates, $viewPaths, $failures, $trustedEnvironment);

        if ($templatesFullyDiscovered) {
            $manifest->prune($templates);
        }

        $manifest->flush();
        $this->reportFailures($failures);

        if ($shadows === []) {
            return false;
        }

        // Every discovered template, not just the compiled ones: UnusedView has to be able to report
        // on a template that failed to compile too, and Config::reportIssueInFile() only ever
        // consults this project-file list (see ViewReferenceRegistry / UnusedViewHandler).
        if (!$this->registrar->markTemplatesReportable($templates)) {
            $this->degrade(
                "issues found in Blade templates could not be made reportable (Psalm's internal project-file "
                . 'list is not writable on this Psalm version)',
            );

            return false;
        }

        // Every shadow's prelude carries the ambient classes only in stacked docblocks, and Psalm's
        // scanner only ever sees the last one (see ShadowRegistrar::queueClassLikesForScanning);
        // queue them here, once per run, so a warm-manifest run (which skips ShadowCompiler entirely)
        // still gets them.
        $this->registrar->queueClassLikesForScanning(PreludeBuilder::ambientClassNames());

        // #1505: a vendor directive can compile a class name into a PHP string literal
        // (`app('Vendor\Package\Class')::method()`) instead of code position or a docblock, which
        // neither Psalm's scanner nor the ambient queue above ever sees. Read every shadow off DISK
        // rather than the fresh compile result above: on a warm-manifest run compileAll() never
        // invokes ShadowCompiler at all (isFresh() short-circuits per template), so the file is the
        // only source that exists on every run, not just a fresh one. This runs for every shadow
        // every run, fresh or warm; the per-file token scan costs single-digit milliseconds even
        // across a thousand shadows, so no manifest slot caches the result.
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

        // The enqueue goes last because it is the one irreversible step: everything above can fail
        // and leave the run indistinguishable from Blade having never started. The remap entries
        // publish in a finally rather than after, because they only RELOCATE issues onto the
        // template they came from — a partial enqueue that then threw would otherwise report raw
        // shadow paths, which is strictly worse than entries for shadows nothing analyzed.
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

        foreach ($this->pendingReferences as $viewName) {
            ViewReferenceRegistry::addReference($viewName);
        }

        if ($this->pendingDynamic) {
            ViewReferenceRegistry::markDynamic();
        }
    }

    /**
     * @param list<string>                              $templates
     * @param list<array{0: string, 1: string|null}>     $viewPaths path, namespace pairs in finder
     *                                                     order, which decides which template wins a
     *                                                     view name two roots both define
     * @param array<string, string>                      $failures  template path => reason, appended to
     * @param bool                                        $trustedEnvironment when false,
     *                                                     {@see CompilerEnvironment::describe()} could
     *                                                     not resolve every compiler input, so the
     *                                                     freshness check is skipped and every template
     *                                                     recompiles this run regardless of the manifest
     *
     * @return array<string, string> template path => shadow path
     */
    private function compileAll(
        ShadowCompiler $compiler,
        ShadowManifest $manifest,
        array $templates,
        array $viewPaths,
        array &$failures,
        bool $trustedEnvironment,
    ): array {
        $shadows = [];
        $roots = $this->resolveRoots($viewPaths);
        $parser = new ContractParser();
        $collector = $this->collectViewReferences || $this->collectDataIncludes ? new ViewReferenceCollector() : null;
        $requiredSlots = ($this->collectViewReferences ? ShadowManifest::SLOT_REFERENCES : 0)
            | ($this->collectDataIncludes ? ShadowManifest::SLOT_DATA_INCLUDES : 0);

        foreach ($templates as $template) {
            $source = @\file_get_contents($template);

            if ($source === false) {
                $failures[$template] = 'the template could not be read';
                $this->claimNameOnly($template, $roots);

                continue;
            }

            // Recorded off the bytes that compiled, or that a freshness hit proved identical to
            // them, because the relocator reads the template again later and a prefix re-derived
            // from changed bytes silently disables the marker strip ({@see ShadowTarget}).
            ShadowRegistry::registerMarkerPrefix($template, MarkerComment::prefixFor($source));

            if ($trustedEnvironment && $manifest->isFresh($template, $source, $requiredSlots)) {
                $shadowPath = $manifest->shadowPathFor($template, $source);
                $shadows[$template] = $shadowPath;
                $this->registerContract(
                    $template,
                    $roots,
                    $manifest->contractFor($shadowPath),
                    $manifest->dataIncludesFor($shadowPath),
                );

                if ($this->collectViewReferences) {
                    $this->applyReferences($manifest->referencesFor($shadowPath) ?? [[], false]);
                }

                continue;
            }

            // Deliberately NOT fed into compile(): contract types in the prelude would change every
            // shadow's content and fingerprint. Declarations are read as a side channel for
            // call-site validation only — which is also why the contract is built AFTER the compile,
            // so the read set can be taken off the compiled output without reaching compile().
            $shadow = $compiler->compile($template, $source);

            if ($shadow instanceof BladeCompileError) {
                $failures[$shadow->templatePath] = $shadow->message;
                $this->claimNameOnly($template, $roots);

                continue;
            }

            $contract = $this->collectDataIncludes
                ? $parser->parseDataContract($source, $shadow->contents)
                : $parser->parseDeclarations($source);

            // Read from the compiled output, not the raw template: Laravel has already resolved
            // component namespaces and anonymous-component candidates by this point. Null (not an
            // empty pair) when the rule is off, so a later flag flip cannot mistake "never
            // collected" for "collected, found nothing" — see ShadowManifest::isFresh().
            $references = $this->collectViewReferences ? $collector?->collectFromSource($shadow->contents) : null;
            $dataIncludes = $this->collectDataIncludes ? $collector?->collectDataIncludes($shadow->contents) : null;

            try {
                $shadowPath = $manifest->store($template, $source, $shadow, $contract, $references, $dataIncludes);
                $shadows[$template] = $shadowPath;
                $this->registerContract($template, $roots, $contract, $dataIncludes);

                if ($references !== null) {
                    $this->applyReferences($references);
                }
            } catch (\RuntimeException $throwable) {
                $failures[$template] = $throwable->getMessage();
                $this->claimNameOnly($template, $roots);
            }
        }

        return $shadows;
    }

    /** @param array{0: list<string>, 1: bool} $references */
    private function applyReferences(array $references): void
    {
        foreach ($references[0] as $viewName) {
            $this->pendingReferences[] = $viewName;
        }

        if ($references[1]) {
            $this->pendingDynamic = true;
        }
    }

    /**
     * View roots as realpaths, in finder order, skipping the ones that do not resolve, deduped by
     * (path, namespace): a published override's directory is both the last segment of the default
     * root AND a namespace's own hint root, and both names it earns have to survive. The template
     * paths this is matched against are realpaths too, so both sides have to be normalized or a
     * symlinked root never matches its own templates.
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

            $resolved = \rtrim($resolved, \DIRECTORY_SEPARATOR);
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
     * Claim a view name for a template this pass could not process, with no declarations attached.
     *
     * Laravel renders the first root's file whether or not the plugin could read or compile it, so
     * leaving the name unclaimed hands it to a same-named template in a later root, whose
     * declarations would then be checked against callers that never reach it. An empty contract
     * blocks that without asserting anything about a template we failed on.
     *
     * @param list<array{0: string, 1: string|null}> $roots
     */
    private function claimNameOnly(string $templatePath, array $roots): void
    {
        $this->registerContract($templatePath, $roots, new ViewDataContract([], false));

        // Its own @include/@extends references are unknown, not empty: treating them as empty would
        // cascade into false UnusedView positives on everything this template actually renders.
        $this->pendingDynamic = true;
    }

    /**
     * A template that declares nothing is registered too, with an empty contract. Skipping it would
     * leave its view name unclaimed, and a same-named template in a LATER view root would then own
     * the name and have its declarations checked against callers that Laravel resolves to this
     * file instead. The view name is claimed as an UnusedView CANDIDATE unconditionally, even for a
     * template this pass could not process — see {@see claimNameOnly()}.
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
        // A published override's file matches more than one root (its default-root name AND its
        // namespace's own name) — every match gets registered, or one of the two names a call site
        // can legitimately use resolves to nothing.
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
     * View roots paired with their namespace (null for the default, unqualified roots), or null when
     * the finder cannot be resolved. `getPaths()` roots come first, in finder order — deciding which
     * template wins a name two roots both define — followed by each `getHints()` namespace's own
     * paths, in the finder's own order (a namespace's published override before its package
     * fallback, see `ServiceProvider::loadViewsFrom()`). `loadViewsFrom()` populates hints via a
     * `callAfterResolving('view')` callback, so `resolveFinder()` touches the `'view'` binding itself
     * rather than depending on some earlier, unrelated resolve to have already fired it.
     *
     * Hint roots inside the analyzed project's Composer vendor directory are dropped:
     * `ViewServiceProvider`, `NotificationServiceProvider`, and `PaginationServiceProvider` all
     * register their OWN internal templates this exact same way, on every application,
     * unconditionally. `notifications::email` alone carries a `<x-mail::…>` component tag, which the
     * reference collector cannot resolve — unioning it in would disable UnusedView, permanently, for
     * every project the moment Blade analysis is enabled. A project's own published override
     * (`resources/views/vendor/<namespace>`, see {@see ViewName}) is NOT inside the vendor
     * directory — the boundary is the Composer install root, not the literal substring "vendor" —
     * so that case is unaffected. `getPaths()` roots are never filtered: Laravel never configures one
     * inside the vendor directory.
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
            $roots[] = [$path, null];
        }

        $vendorDir = $this->vendorDirectory();

        // FileViewFinder's own docblock says only `array<string, array>`, but addNamespace()
        // casts to a string list; narrowed here because Psalm cannot see through the cast.
        /** @psalm-var array<string, list<string>> $hints */
        $hints = $finder->getHints();

        foreach ($hints as $namespace => $hintPaths) {
            foreach ($hintPaths as $hint) {
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
     * Whether Laravel itself would resolve `$viewName` to `$templatePath`. Only consulted for
     * qualified names in a namespace that lost a hint root to the vendor filter: there, an earlier
     * (dropped) root can still own the name, and letting a surviving root's same-named template
     * claim it would cover the file with references that never reach it and check callers against
     * a contract Laravel never renders. Everywhere else root order mirrors the finder exactly, so
     * no filesystem probe is spent. A finder failure keeps the claim (the pre-check behavior).
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

        return $winner === $templatePath;
    }

    /**
     * The analyzed project's own Composer vendor directory, or the test override. Null when it
     * cannot be determined, in which case the vendor filter above is skipped rather than guessed
     * at. See {@see VendorDirectory} for why the boundary is the
     * install root and not the substring `vendor`.
     */
    private function vendorDirectory(): ?string
    {
        return $this->vendorDirOverride ?? VendorDirectory::path();
    }

    private function isUnderVendorDirectory(string $path, string $vendorDir): bool
    {
        return VendorDirectory::contains($path, $vendorDir);
    }

    /**
     * `ViewServiceProvider::registerViewFinder()` binds 'view.finder' with `bind()`, not
     * `singleton()` — every `make('view.finder')` constructs a BRAND NEW `FileViewFinder`, none of
     * which carries the namespace hints `loadViewsFrom()` added to the ONE finder instance the
     * 'view' Factory singleton captured at its own construction. Resolving 'view' and reading
     * `getFinder()` off it is therefore the only path that ever sees hints; 'view.finder' is kept
     * only as a fallback for a boot that bound it directly without a Factory at all.
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
     */
    private function asCompiler(mixed $resolved): ?BladeCompiler
    {
        return $resolved instanceof BladeCompiler ? $resolved : null;
    }

    private function asFinder(mixed $resolved): ?FileViewFinder
    {
        return $resolved instanceof FileViewFinder ? $resolved : null;
    }

    private function asFactoryFinder(mixed $resolved): ?FileViewFinder
    {
        return $resolved instanceof Factory ? $this->asFinder($resolved->getFinder()) : null;
    }

    /**
     * Every `*.blade.php` file under the view roots, realpath'd and deduplicated: a path registered
     * with Psalm has to be the same string Psalm itself would use, and view roots overlap in
     * applications that add a package path twice.
     *
     * @param list<array{0: string, 1: string|null}> $viewPaths
     * @param array<string, string>                  $failures appended to, keyed by the directory
     *                                                that failed
     *
     * @return list<string>
     */
    private function findTemplates(array $viewPaths, array &$failures): array
    {
        $templates = [];

        foreach ($viewPaths as [$viewPath]) {
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
                        $templates[$real] = true;
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
