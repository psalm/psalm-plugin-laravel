<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade\Annotate;

use Psalm\LaravelPlugin\Blade\ContractRegistry;
use Psalm\LaravelPlugin\Blade\ViewDataContract;
use Psalm\LaravelPlugin\Blade\ViewReferenceRegistry;
use Psalm\Plugin\EventHandler\AfterAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterAnalysisEvent;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\StrictUnifiedDiffOutputBuilder;

/**
 * Turns what {@see AnnotationCollector} saw into `{{-- @var --}}` declarations on disk.
 *
 * Runs in AfterAnalysis, never from a per-statement hook: mid-analysis the template's shadow is
 * already parsed and cached, so rewriting the source then would put the run and the file out of
 * step. By AfterAnalysis every call site has been seen, which is also what makes the
 * every-producer-agrees rule answerable at all.
 *
 * Insertion only. A variable the template already declares is never rewritten, in either spelling —
 * narrowing a declaration a human wrote needs a containment check this release does not make.
 *
 * @internal
 */
final class AnnotationWriter implements AfterAnalysisInterface
{
    private static ?AnnotateRequest $request = null;

    /** @psalm-external-mutation-free */
    public static function init(AnnotateRequest $request): void
    {
        self::$request = $request;
    }

    /** @psalm-external-mutation-free */
    public static function reset(): void
    {
        self::$request = null;
    }

    #[\Override]
    public static function afterAnalysis(AfterAnalysisEvent $event): void
    {
        $request = self::$request;

        if ($request instanceof AnnotateRequest) {
            self::run($request);
        }
    }

    /**
     * The write itself, split from the hook so the single-threaded guard below is reachable from a
     * test without standing up an AfterAnalysisEvent.
     */
    public static function run(AnnotateRequest $request): void
    {
        // A parent that analysed nothing is a forked run: every producer type is in a worker that
        // has already exited, so every variable would be declared `mixed` — across the whole view
        // tree, silently, with exit code 0. Refuse rather than write that.
        if (!AnnotationCollector::sawAnalysis()) {
            $request->publishError(
                'the analysis ran in forked workers, whose results never reach the writing process. '
                . 'Re-run with --threads=1.',
            );

            return;
        }

        // Re-checked here, not only in publish(): the templates are written well before the result
        // is, and the control file is named by an environment variable that can be repointed while
        // the analysis runs.
        if (!$request->isValid()) {
            return;
        }

        $changed = [];
        $failures = [];
        $diff = '';

        foreach (self::plans() as $templatePath => $vars) {
            try {
                $hunk = self::apply($templatePath, $vars, $request->dryRun);
            } catch (\Throwable $throwable) {
                // One unwritable template must not take the whole pass down with it: by AfterAnalysis
                // the run is otherwise finished, and an escaping throwable would lose every other
                // template's write along with Psalm's own report.
                $failures[$templatePath] = $throwable->getMessage();

                continue;
            }

            if ($hunk === null) {
                continue;
            }

            $changed[$templatePath] = \array_keys($vars);
            $diff .= $hunk;
        }

        $request->publish($changed, $failures, $diff);
    }

    /**
     * One plan per template FILE, not per view name: a published override is claimed under both its
     * default-root name and its namespace's qualified name, and annotating the file twice would
     * report it twice. A name the two names' call sites type differently falls back to `mixed`.
     *
     * @return array<string, array<string, string>> template path => variable name => type string
     */
    private static function plans(): array
    {
        $plans = [];

        foreach (ViewReferenceRegistry::templates() as $viewName => $templatePath) {
            // PHP stored a numeric view name (`123.blade.php` is legal) under the int it cast the
            // key to, and every lookup below is typed on string.
            $contract = ContractRegistry::contractFor((string) $viewName);

            if (!$contract instanceof ViewDataContract) {
                continue;
            }

            foreach (self::plan((string) $viewName, $contract) as $name => $type) {
                $existing = $plans[$templatePath][$name] ?? null;

                $plans[$templatePath][$name] = $existing === null || $existing === $type ? $type : 'mixed';
            }
        }

        return $plans;
    }

    /**
     * The variables one view name needs declared, and the type to declare each as.
     *
     * @return array<string, string> variable name (without `$`) => type string
     */
    public static function plan(string $viewName, ViewDataContract $contract): array
    {
        // A body that hides which names it reads (`@props` and `@aware` both compile to `$$name`)
        // gives a read set that is only a lower bound. Annotating part of a contract is worse than
        // annotating none of it: the missing names become MissingViewVariable noise at call sites.
        if ($contract->readsUnknown) {
            return [];
        }

        $plan = [];

        // A `@foreach ($items as $item)` alias is read by the template and bound by it, so it stays
        // in the read set (UnusedViewData asks "is this name used at all") but must never be
        // declared: declaring it reports MissingViewVariable at every correct call site, which is
        // the check this codemod exists to feed.
        $loopAliases = \array_fill_keys($contract->loopVariables, true);

        foreach ($contract->readVariables as $name) {
            if (isset($contract->vars[$name]) || isset($loopAliases[$name])) {
                continue;
            }

            // A call site that renders this view with a provably closed data set NOT carrying the
            // name proves the template works without it — it reads the name guarded (`$x ?? ''`,
            // `@isset`), and declaring it would report MissingViewVariable at that call site.
            if (AnnotationCollector::isOptional($viewName, $name)) {
                continue;
            }

            $plan[$name] = AnnotationCollector::typeFor($viewName, $name) ?? 'mixed';
        }

        return $plan;
    }

    /**
     * Declare `$vars` in the template at `$templatePath`, or work out what that would do.
     *
     * @param array<string, string> $vars variable name (without `$`) => type string
     *
     * @return string|null a unified-diff hunk for what changed, null when the template already
     *                     declares every name
     *
     * @throws \RuntimeException when the template cannot be read, or written back
     */
    public static function apply(string $templatePath, array $vars, bool $dryRun): ?string
    {
        $source = @\file_get_contents($templatePath);

        if ($source === false) {
            throw new \RuntimeException('the template could not be read');
        }

        $result = TemplateAnnotator::annotate($source, $vars);

        if ($result === null) {
            return null;
        }

        $annotated = $result[0];

        if (!$dryRun && @\file_put_contents($templatePath, $annotated) === false) {
            throw new \RuntimeException('the template could not be written');
        }

        return self::hunk($templatePath, $source, $annotated);
    }

    /**
     * The insertion as a unified-diff hunk, diffed from the real before/after source rather than
     * hand-assembled: a line the insertion also terminates (an EOL added to a template's last,
     * previously bare, line) then shows as the modification it really is instead of a false pure
     * addition, and the surrounding context lines this produces are what let plain `git apply`
     * (no `--unidiff-zero`) accept the output.
     */
    private static function hunk(string $templatePath, string $before, string $after): string
    {
        $header = self::headerPath($templatePath);

        $differ = new Differ(new StrictUnifiedDiffOutputBuilder([
            'fromFile' => self::prefixed('a', $header),
            'toFile' => self::prefixed('b', $header),
        ]));

        return $differ->diff($before, $after);
    }

    /**
     * The template path as `git apply` expects it in a hunk header: relative to the project root
     * when the template is under one, since the `blade:annotate` CLI launches its child Psalm
     * process with the project root as that process's own working directory — this is that root,
     * not one invented here. A relative header is what lets a plain `git apply`, run from the same
     * root, use git's default single-component strip; a template outside the root (or a run whose
     * working directory cannot be read) falls back to the path as given. Either way, directory
     * separators are normalized to `/`: a Windows path's backslashes are not diff syntax.
     */
    private static function headerPath(string $templatePath): string
    {
        $normalized = \str_replace('\\', '/', $templatePath);
        $root = \getcwd();

        if ($root === false) {
            return $normalized;
        }

        $root = \rtrim(\str_replace('\\', '/', $root), '/') . '/';

        return \str_starts_with($normalized, $root) ? \substr($normalized, \strlen($root)) : $normalized;
    }

    /** `git apply`'s default strip removes exactly one path component: "a/" ahead of a relative path, but only "a" ahead of one already rooted at "/" — otherwise the leading slash doubles. */
    private static function prefixed(string $letter, string $path): string
    {
        return \str_starts_with($path, '/') ? "{$letter}{$path}" : "{$letter}/{$path}";
    }
}
