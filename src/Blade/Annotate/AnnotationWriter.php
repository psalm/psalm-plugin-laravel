<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade\Annotate;

use Illuminate\View\ViewName;
use Psalm\Internal\Analyzer\IssueData;
use Psalm\LaravelPlugin\Blade\ContractRegistry;
use Psalm\LaravelPlugin\Blade\ViewDataContract;
use Psalm\LaravelPlugin\Blade\ViewReferenceRegistry;
use Psalm\Plugin\EventHandler\AfterAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterAnalysisEvent;
use SebastianBergmann\Diff\Differ;
use SebastianBergmann\Diff\Output\StrictUnifiedDiffOutputBuilder;

/**
 * Turns what {@see AnnotationCollector} saw into native `@var` declarations on disk.
 *
 * Runs in AfterAnalysis, never from a per-statement hook: mid-analysis the template's shadow is
 * already parsed and cached, so rewriting the source then would put the run and the file out of
 * step. By AfterAnalysis every call site has been seen, which is also what makes the
 * every-producer-agrees rule answerable at all.
 *
 * A name no call site types is listed back instead of declared: a `mixed` declaration adds no
 * type, yet makes the name required at every call site and hides that it is still untyped. So is a
 * name the call sites type in ways no declaration covers, with each type they pass.
 *
 * One splice per template (see {@see TemplateAnnotator} for where): at most a one-line header docblock
 * is opened out. A variable the template already declares is never rewritten, in either spelling —
 * narrowing a declaration a human wrote needs a containment check this release does not make.
 *
 * @internal
 */
final class AnnotationWriter implements AfterAnalysisInterface
{
    private static ?AnnotateRequest $request = null;

    public static function init(AnnotateRequest $request): void
    {
        self::$request = $request;
    }

    public static function reset(): void
    {
        self::$request = null;
    }

    #[\Override]
    public static function afterAnalysis(AfterAnalysisEvent $event): void
    {
        $request = self::$request;

        if ($request instanceof AnnotateRequest) {
            self::run($request, $event->getIssues());
        }
    }

    /**
     * The write itself, split from the hook so the single-threaded guard below is reachable from a
     * test without standing up an AfterAnalysisEvent.
     *
     * @param array<string, list<IssueData>> $issues what the analysis reported, by file path
     */
    public static function run(AnnotateRequest $request, array $issues = []): void
    {
        // A parent that analysed nothing is a forked run: every producer type is in a worker that
        // has already exited, so every variable would be reported untyped — across the whole view
        // tree, with exit code 0. Refuse rather than report that.
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
        $unknown = [];
        $unreadable = [];
        $diff = '';
        $parseErrors = self::markUnparsedProducers($issues);
        [$plans, $disagreed] = self::plans();

        foreach ($plans as $templatePath => [$planned, $optional, $disagreeing, $site]) {
            $vars = \array_filter($planned, static fn(?string $type): bool => $type !== null);
            $untyped = \array_keys(\array_diff_key($planned, $vars, $disagreeing));

            if ($untyped !== [] && $site !== null) {
                $unreadable[$templatePath] = ['site' => $site, 'names' => $untyped];
            } elseif ($untyped !== []) {
                $unknown[$templatePath] = $untyped;
            }

            if ($vars === []) {
                continue;
            }

            try {
                $hunk = self::apply($templatePath, $vars, $request->dryRun, $optional);
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

        $request->publish($changed, $failures, $diff, $unknown, $disagreed, $unreadable, $parseErrors);
    }

    /**
     * Psalm analyses the statements it could recover from a file it failed to parse, and the rest are
     * invisible: any `view()` call there is a producer the pass never saw, which could contradict what it
     * is about to write. So every view whose name appears as a string literal in such a file counts as
     * rendered by a call site that cannot be read. Found by tokenizing, which needs no valid syntax.
     *
     * A `ParseError` is also what Psalm reports for some docblock mistakes: marking is then merely
     * conservative, and the message names the file either way.
     *
     * @param array<string, list<IssueData>> $issues
     *
     * @return array<string, string> file path => the first parse error, as `line N: message`
     */
    private static function markUnparsedProducers(array $issues): array
    {
        $errors = [];

        foreach ($issues as $reported) {
            foreach ($reported as $issue) {
                if ($issue->type === 'ParseError') {
                    $errors[$issue->file_path] ??= "line {$issue->line_from}: {$issue->message}";
                }
            }
        }

        \ksort($errors);

        foreach (\array_keys($errors) as $path) {
            $source = @\file_get_contents($path);

            if ($source === false) {
                // Nothing can be said about what it renders: every view is then unreadable.
                foreach (\array_keys(ViewReferenceRegistry::templates()) as $viewName) {
                    AnnotationCollector::markUnreadable((string) $viewName, $path);
                }

                continue;
            }

            foreach (@\token_get_all($source) as $token) {
                if (!\is_array($token) || $token[0] !== \T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }

                // Under the name Laravel renders, as AnnotationCollector marks it.
                $viewName = \trim(ViewName::normalize(\substr($token[1], 1, -1)));

                if (ViewReferenceRegistry::templatePathFor($viewName) !== null) {
                    AnnotationCollector::markUnreadable($viewName, "{$path}:{$token[2]}");
                }
            }
        }

        return $errors;
    }

    /**
     * One plan per template FILE, not per view name: a published override is claimed under both its
     * default-root name and its namespace's qualified name, and annotating the file twice would
     * report it twice. A name the two names' call sites type differently has no type.
     *
     * @return array{
     *     0: array<string, array{0: array<string, string|null>, 1: array<string, true>, 2: array<string, true>, 3: string|null}>,
     *     1: array<string, array<string, list<string>>>,
     * } template path => [variable name => type string (null when unknown), the names a closed call
     *   site omits, the names its call sites disagree on, the first call site that left it open (null
     *   when none did)]; and per view name, each name its call sites disagree on => the distinct types
     *   they pass
     */
    private static function plans(): array
    {
        $plans = [];
        $optional = [];
        $disagreeing = [];
        $disagreed = [];
        $sites = [];
        $unrendered = [];

        foreach (ViewReferenceRegistry::templates() as $viewName => $templatePath) {
            // PHP stored a numeric view name (`123.blade.php` is legal) under the int it cast the
            // key to, and every lookup below is typed on string.
            $contract = ContractRegistry::contractFor((string) $viewName);

            if (!$contract instanceof ViewDataContract) {
                continue;
            }

            $sites[$templatePath] ??= AnnotationCollector::unreadableSite((string) $viewName);
            $rendered = AnnotationCollector::hasProducer((string) $viewName);

            foreach (self::plan((string) $viewName, $contract) as $name => $type) {
                // A name nothing renders (a published override's `vendor.pkg.card` beside the `pkg::card`
                // its callers use) proves nothing about the file, so it must not veto the other name's type.
                if (!$rendered) {
                    $unrendered[$templatePath][$name] = null;

                    continue;
                }

                $plans[$templatePath][$name] = !\array_key_exists($name, $plans[$templatePath] ?? [])
                    || $plans[$templatePath][$name] === $type ? $type : null;

                // Optional once a closed call site omits it under ANY of the file's names: that call
                // renders this same file without it.
                if (AnnotationCollector::isOptional((string) $viewName, $name)) {
                    $optional[$templatePath][$name] = true;
                }

                $types = $type === null ? AnnotationCollector::disagreeingTypes((string) $viewName, $name) : null;

                if ($types !== null) {
                    $disagreeing[$templatePath][$name] = true;
                    $disagreed[(string) $viewName][$name] = $types;
                }
            }
        }

        // Still planned, as unknown, when no other name of the file renders it.
        foreach ($unrendered as $templatePath => $names) {
            $plans[$templatePath] = ($plans[$templatePath] ?? []) + $names;
        }

        $result = [];

        foreach ($plans as $templatePath => $vars) {
            $result[$templatePath] = [$vars, $optional[$templatePath] ?? [], $disagreeing[$templatePath] ?? [], $sites[$templatePath] ?? null];
        }

        return [$result, $disagreed];
    }

    /**
     * The variables one view name needs declared, and the type to declare each as.
     *
     * @return array<string, string|null> variable name (without `$`) => type string, null when no
     *         call site proves one (`mixed` included)
     */
    public static function plan(string $viewName, ViewDataContract $contract): array
    {
        $plan = [];

        foreach (self::plannedNames($contract) as $name) {
            // A name some closed call site omits is still declared, but as `T|null $x = null`
            // (see plans()): a plain declaration would report MissingViewData at that call site.
            $type = AnnotationCollector::typeFor($viewName, $name);
            $plan[$name] = $type === 'mixed' ? null : $type;
        }

        return $plan;
    }

    /**
     * The type {@see self::plans()} would declare for one variable of a template file, and whether
     * it would be declared `= null`: the answer an `@includeIsolated` in that file hands its child
     * ({@see AnnotationCollector::typeFor()}). The file's view names must agree, as they do in
     * plans(). Null when the file plans no such name; its type null when no type is proven.
     *
     * @return array{0: string|null, 1: bool}|null
     */
    public static function fileVariable(string $templatePath, string $name): ?array
    {
        $found = false;
        $agreed = false;
        $type = null;
        $optional = false;

        foreach (ViewReferenceRegistry::namesFor($templatePath) as $viewName) {
            $contract = ContractRegistry::contractFor($viewName);

            if (!$contract instanceof ViewDataContract || !\in_array($name, self::plannedNames($contract), true)) {
                continue;
            }

            $found = true;

            // As in plans(): a name nothing renders takes no part in the agreement.
            if (!AnnotationCollector::hasProducer($viewName)) {
                continue;
            }

            $planned = AnnotationCollector::typeFor($viewName, $name);
            $planned = $planned === 'mixed' ? null : $planned;
            $type = $agreed && $type !== $planned ? null : $planned;
            $agreed = true;
            $optional = $optional || AnnotationCollector::isOptional($viewName, $name);
        }

        return $found ? [$type, $optional] : null;
    }

    /**
     * The names of a contract that still need a declaration.
     *
     * A body that hides which names it reads (`@props` and `@aware` both compile to `$$name`)
     * gives a read set that is only a lower bound. Annotating part of a contract is worse than
     * annotating none of it: the missing names become MissingViewData noise at call sites.
     *
     * A name the template binds for itself — a `@foreach ($items as $item)` alias, a
     * `@php ($heading = ...)` assignment, a `fn ($item) => ...` parameter — is read by the
     * template but supplied by it too. It stays in the read set (UnusedViewData asks "is this
     * name used at all") and must never be declared: declaring it reports MissingViewData
     * at every correct call site, which is the check this codemod exists to feed.
     *
     * A name declared by a raw docblock that is not a contract var (a local's type hint) joins
     * them: `TemplateAnnotator` reads that spelling back, so planning one produces no insertion
     * anyway, and reporting it as changed would be a lie.
     *
     * @return list<string>
     *
     * @psalm-pure
     */
    private static function plannedNames(ViewDataContract $contract): array
    {
        if ($contract->readsUnknown) {
            return [];
        }

        $localNames = \array_fill_keys($contract->localVariables, true)
            + \array_fill_keys($contract->rawDeclaredVariables, true);

        return \array_values(\array_filter(
            $contract->readVariables,
            // A `$__data['…']` read can name any key; only a valid identifier can be declared as `$name`.
            static fn(string $name): bool => !isset($contract->vars[$name]) && !isset($localNames[$name])
                && \preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/D', $name) === 1,
        ));
    }

    /**
     * Declare `$vars` in the template at `$templatePath`, or work out what that would do.
     *
     * @param array<string, string> $vars variable name (without `$`) => type string
     * @param array<string, true> $optional names to declare `= null`
     *
     * @return string|null a unified-diff hunk for what changed, null when the template already
     *                     declares every name
     *
     * @throws \RuntimeException when the template cannot be read, or written back
     */
    public static function apply(string $templatePath, array $vars, bool $dryRun, array $optional = []): ?string
    {
        $source = @\file_get_contents($templatePath);

        if ($source === false) {
            throw new \RuntimeException('the template could not be read');
        }

        $annotated = TemplateAnnotator::annotate($source, $vars, $optional);

        if ($annotated === null) {
            return null;
        }

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

    /**
     * `git apply`'s default strip removes exactly one path component: "a/" ahead of a relative path, but only "a" ahead of one already rooted at "/" — otherwise the leading slash doubles.
     *
     * @psalm-pure
     */
    private static function prefixed(string $letter, string $path): string
    {
        return \str_starts_with($path, '/') ? "{$letter}{$path}" : "{$letter}/{$path}";
    }
}
