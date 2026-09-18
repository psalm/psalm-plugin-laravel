<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Illuminate\Contracts\Container\Container;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
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
    ) {}

    public function boot(): void
    {
        try {
            $this->run();
        } catch (\Throwable $throwable) {
            $this->degrade($throwable::class . ': ' . $throwable->getMessage());
        }
    }

    private function run(): void
    {
        $compiler = $this->resolveCompiler();

        if (!$compiler instanceof BladeCompiler) {
            return;
        }

        $viewPaths = $this->resolveViewPaths();

        if ($viewPaths === null) {
            return;
        }

        /** @var array<string, string> $failures template path => reason */
        $failures = [];
        $templates = $this->findTemplates($viewPaths, $failures);

        // No templates is the normal state of a package or an API-only application, not a failure.
        if ($templates === []) {
            $this->reportFailures($failures);

            return;
        }

        $shadowDir = $this->prepareShadowDir();

        if ($shadowDir === null) {
            return;
        }

        $manifest = new ShadowManifest($shadowDir);
        $manifest->load();

        $shadows = $this->compileAll(new ShadowCompiler($compiler), $manifest, $templates, $failures);

        $manifest->prune($templates);
        $manifest->flush();
        $this->reportFailures($failures);

        if ($shadows === []) {
            return;
        }

        // Templates first: a shadow Psalm cannot report on is pure analysis cost, so a failed write
        // cancels the whole registration rather than half of it.
        if (!$this->registrar->markTemplatesReportable(\array_keys($shadows))) {
            $this->degrade(
                "issues found in Blade templates could not be made reportable (Psalm's internal project-file "
                . 'list is not writable on this Psalm version)',
            );

            return;
        }

        $this->registrar->registerShadowsForAnalysis(\array_values($shadows));
    }

    /**
     * @param list<string>          $templates
     * @param array<string, string> $failures  template path => reason, appended to
     *
     * @return array<string, string> template path => shadow path
     */
    private function compileAll(
        ShadowCompiler $compiler,
        ShadowManifest $manifest,
        array $templates,
        array &$failures,
    ): array {
        $shadows = [];

        foreach ($templates as $template) {
            $source = @\file_get_contents($template);

            if ($source === false) {
                $failures[$template] = 'the template could not be read';

                continue;
            }

            if ($manifest->isFresh($template, $source)) {
                $shadows[$template] = $manifest->shadowPathFor($template);

                continue;
            }

            // Contract variables arrive with the extraction slice; until then every template
            // variable is mixed.
            $shadow = $compiler->compile($template, $source);

            if ($shadow instanceof BladeCompileError) {
                $failures[$shadow->templatePath] = $shadow->message;

                continue;
            }

            try {
                $shadows[$template] = $manifest->store($template, $source, $shadow);
            } catch (\RuntimeException $throwable) {
                $failures[$template] = $throwable->getMessage();
            }
        }

        return $shadows;
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
     * View roots, or null when the finder cannot be resolved. Mirrors the fallback chain the
     * MissingView diagnostic uses: a boot may bind 'view' without 'view.finder'.
     *
     * @return list<string>|null
     */
    private function resolveViewPaths(): ?array
    {
        $finder = $this->resolveFinder();

        if (!$finder instanceof FileViewFinder) {
            $this->degrade('the view finder could not be resolved to an ' . FileViewFinder::class);

            return null;
        }

        return \array_values($finder->getPaths());
    }

    private function resolveFinder(): ?FileViewFinder
    {
        try {
            if ($this->app->bound('view.finder')) {
                return $this->asFinder($this->app->make('view.finder'));
            }

            if ($this->app->bound('view')) {
                return $this->asFactoryFinder($this->app->make('view'));
            }
        } catch (\Throwable $throwable) {
            $this->output->debug('Laravel plugin: resolving the view finder threw: ' . $throwable->getMessage() . "\n");
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
     * @param list<string>          $viewPaths
     * @param array<string, string> $failures  appended to, keyed by the directory that failed
     *
     * @return list<string>
     */
    private function findTemplates(array $viewPaths, array &$failures): array
    {
        $templates = [];

        foreach ($viewPaths as $viewPath) {
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
