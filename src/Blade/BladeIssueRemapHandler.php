<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Psalm\Config;
use Psalm\Internal\Codebase\TaintFlowGraph;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\BeforeAddIssueInterface;
use Psalm\Plugin\EventHandler\Event\BeforeAddIssueEvent;

/**
 * Moves every issue Psalm finds in a compiled shadow onto the `.blade.php` file and line it came
 * from. Without it the Blade pipeline produces no user-visible output at all: a shadow is
 * deliberately never made reportable, so its issues die in Psalm's reportability gate.
 *
 * `BeforeAddIssue` is the only hook that works here, because Psalm dispatches it BEFORE both that
 * gate and `IssueBuffer::isSuppressed()`. The handler kills the shadow-path original by returning
 * false and re-emits the rebuilt issue through `IssueBuffer::accepts()`, which is also what applies
 * the template's own suppressions: the event carries the issue but not the suppressed-issue list
 * that was about to be checked against it, so the list has to be rebuilt from the registry.
 *
 * Re-entrancy is guarded twice. Structurally, the re-emitted issue sits on the `.blade.php` path,
 * which is never a registry key, so the second pass declines on the lookup; the flag additionally
 * covers a future change that makes templates registry keys too.
 *
 * @psalm-api
 *
 * @internal
 */
final class BladeIssueRemapHandler implements BeforeAddIssueInterface
{
    private static bool $remapping = false;

    public static function reset(): void
    {
        self::$remapping = false;
    }

    #[\Override]
    public static function beforeAddIssue(BeforeAddIssueEvent $event): ?bool
    {
        if (self::$remapping) {
            return null;
        }

        $issue = $event->getIssue();
        $entry = ShadowRegistry::entryFor($issue->getFilePath());

        // The overwhelmingly common case, and the cheapest bail: this hook sees every issue in
        // the run, almost none of which come from a shadow.
        if (!$entry instanceof ShadowEntry) {
            return null;
        }

        // Psalm 6 runs taint exclusively — under a taint graph `IssueBuffer::add()` discards every
        // non-Tainted* issue. Relocating one would only move it to the template to be discarded
        // there, while costing a rebuild per issue.
        if ($event->getCodebase()->taint_flow_graph instanceof TaintFlowGraph
            && !\str_starts_with($issue::getIssueType(), 'Tainted')
        ) {
            return null;
        }

        $templateSource = ShadowRegistry::templateSource($entry->templatePath);

        if ($templateSource === null) {
            return null;
        }

        $relocated = ShadowIssueRelocator::relocate(
            $issue,
            $entry,
            $templateSource,
            Config::getInstance()->shortenFileName($entry->templatePath),
        );

        // Null is a decline, not a drop: Psalm keeps handling the original, which for a shadow
        // means it stays invisible — but nothing is ever silently thrown away here.
        if ($relocated === null) {
            return null;
        }

        if ($relocated === false) {
            return false;
        }

        self::$remapping = true;

        try {
            IssueBuffer::accepts($relocated, $entry->suppressions[$relocated->code_location->getLineNumber()] ?? []);
        } finally {
            self::$remapping = false;
        }

        return false;
    }
}
