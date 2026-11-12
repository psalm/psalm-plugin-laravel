<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

use Psalm\Codebase;
use Psalm\FileManipulation;
use Psalm\Internal\Analyzer\TraitAnalyzer;
use Psalm\Issue\CodeIssue;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Blade\ShadowEntry;
use Psalm\LaravelPlugin\Blade\ShadowRegistry;
use Psalm\StatementsSource;

/**
 * The decision every Psalter-fixable issue makes at its emit site: fix the code, or report the issue.
 *
 * Handlers keep the issue text, its location and every AST-specific guard; this only owns the
 * fix-or-report fork, so a fixable issue cannot forget the suppression check, the Blade and trait gates, or the
 * `$is_fixable` flag that makes Psalm advertise `--issues=<Issue>` on a normal run.
 *
 * @internal
 */
final class PsalterFix
{
    /**
     * @param list<FileManipulation> $replacements the edits that fix this issue, built by the caller. They MUST NOT
     *        overlap other edits of the same file: Psalm keys a range by `start:end` alone and applies distinct
     *        overlapping ranges as they come. An empty list means "this site must not be fixed": the issue is
     *        reported, and not advertised as fixable.
     * @param (\Closure(): bool)|null $costlyGuard a proof too expensive for a plain run (a scan of every scanned class),
     *        asked only once Psalter would apply the fix. False declines the fix: the issue is reported. A plain run
     *        never calls it, so it still advertises such a site as fixable.
     * @return list<FileManipulation>|null the edits for the caller to hand to `$event->setFileReplacements()`
     *         (the issue is then not reported), or null when the issue was reported instead.
     */
    public static function fixOrReport(
        Codebase $codebase,
        StatementsSource $source,
        CodeIssue $issue,
        array $replacements,
        ?\Closure $costlyGuard = null,
    ): ?array {
        $suppressed = $source->getSuppressedIssues();

        // A Blade call lives in a generated shadow: a replacement would rewrite the shadow, never the
        // template, so the finding is reported (remapped onto the template) and not advertised as fixable.
        $isShadow = ShadowRegistry::entryFor($source->getFilePath()) instanceof ShadowEntry;

        // A trait body is analyzed once per consuming class but edited once, so a consumer that proves the edit
        // safe would rewrite the source for the consumers that don't.
        $fixable = !$isShadow && $replacements !== [] && !self::isInTrait($source);

        if ($fixable && $codebase->shouldFixIssue($issue, $suppressed)) {
            if (!$costlyGuard instanceof \Closure || $costlyGuard()) {
                return $replacements;
            }

            $fixable = false;
        }

        IssueBuffer::accepts($issue, $suppressed, $fixable);

        return null;
    }

    /** An unexpectedly deep source chain is not proven to be outside a trait. */
    private static function isInTrait(StatementsSource $source): bool
    {
        for ($current = $source, $depth = 0; $depth < 64; ++$depth) {
            if ($current instanceof TraitAnalyzer) {
                return true;
            }

            $parent = $current->getSource();

            if ($parent === $current) {
                return false;
            }

            $current = $parent;
        }

        return true;
    }
}
