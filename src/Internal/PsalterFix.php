<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

use Psalm\Codebase;
use Psalm\FileManipulation;
use Psalm\Issue\CodeIssue;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Blade\ShadowEntry;
use Psalm\LaravelPlugin\Blade\ShadowRegistry;
use Psalm\StatementsSource;

/**
 * The decision every Psalter-fixable issue makes at its emit site: fix the code, or report the issue.
 *
 * Handlers keep the issue text, its location and every AST-specific guard; this only owns the
 * fix-or-report fork, so a fixable issue cannot forget the suppression check, the Blade gate, or the
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
     * @return list<FileManipulation>|null the edits for the caller to hand to `$event->setFileReplacements()`
     *         (the issue is then not reported), or null when the issue was reported instead.
     */
    public static function fixOrReport(Codebase $codebase, StatementsSource $source, CodeIssue $issue, array $replacements): ?array
    {
        $suppressed = $source->getSuppressedIssues();

        // A Blade call lives in a generated shadow: a replacement would rewrite the shadow, never the
        // template, so the finding is reported (remapped onto the template) and not advertised as fixable.
        $isShadow = ShadowRegistry::entryFor($source->getFilePath()) instanceof ShadowEntry;
        $fixable = !$isShadow && $replacements !== [];

        if ($fixable && $codebase->shouldFixIssue($issue, $suppressed)) {
            return $replacements;
        }

        IssueBuffer::accepts($issue, $suppressed, $fixable);

        return null;
    }
}
