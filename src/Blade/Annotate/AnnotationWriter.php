<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade\Annotate;

use Psalm\LaravelPlugin\Blade\ContractRegistry;
use Psalm\LaravelPlugin\Blade\ViewDataContract;
use Psalm\LaravelPlugin\Blade\ViewReferenceRegistry;
use Psalm\Plugin\EventHandler\AfterAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterAnalysisEvent;

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

        if (!$request instanceof AnnotateRequest) {
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

        foreach ($contract->readVariables as $name) {
            if (isset($contract->vars[$name])) {
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

        [$annotated, $line, $comments] = $result;

        if (!$dryRun && @\file_put_contents($templatePath, $annotated) === false) {
            throw new \RuntimeException('the template could not be written');
        }

        return self::hunk($templatePath, $line, $comments);
    }

    /**
     * The insertion as a unified-diff hunk. Whole lines are only ever added, at one offset, so the
     * added run is the difference in full and no context lines are needed to describe it.
     *
     * @param list<string> $comments
     */
    private static function hunk(string $templatePath, int $line, array $comments): string
    {
        $hunk = "--- a/{$templatePath}\n+++ b/{$templatePath}\n"
            . '@@ -' . ($line - 1) . ",0 +{$line}," . \count($comments) . " @@\n";

        foreach ($comments as $comment) {
            $hunk .= "+{$comment}\n";
        }

        return $hunk;
    }
}
