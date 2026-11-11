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
     * @param non-empty-list<CodeIssue> $issues the findings ONE edit resolves (all of one issue type): several
     *        resolutions of a single `singleton()` closure share its one rename. Fixing drops all of them.
     * @param \Closure(): list<FileManipulation> $replacements built only when fixing, so a plain run never
     *        pays for source lookups. Edits MUST NOT overlap other edits of the same file: Psalm keys a range
     *        by `start:end` alone and applies distinct overlapping ranges as they come. An empty list means
     *        the closure found nothing it can safely edit: the issues are reported, as for a plain run.
     * @return list<FileManipulation>|null the edits for the caller to hand to `$event->setFileReplacements()`
     *         (the issues are then not reported), or null when the issues were reported instead.
     */
    public static function fixOrReport(Codebase $codebase, StatementsSource $source, array $issues, \Closure $replacements): ?array
    {
        $suppressed = $source->getSuppressedIssues();

        // A Blade call lives in a generated shadow: a replacement would rewrite the shadow, never the
        // template, so the finding is reported (remapped onto the template) and not advertised as fixable.
        $isShadow = ShadowRegistry::entryFor($source->getFilePath()) instanceof ShadowEntry;
        $fixable = !$isShadow;

        if ($fixable && $codebase->shouldFixIssue($issues[0], $suppressed)) {
            $edits = $replacements();

            if ($edits !== []) {
                return $edits;
            }

            // Nothing to rewrite means the issue is not fixable here, so Psalm must not advertise it as such.
            $fixable = false;
        }

        foreach ($issues as $issue) {
            IssueBuffer::accepts($issue, $suppressed, $fixable);
        }

        return null;
    }
}
