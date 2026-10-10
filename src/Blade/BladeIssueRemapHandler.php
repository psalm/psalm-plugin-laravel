<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeFinder;
use Psalm\CodeLocation;
use Psalm\Config;
use Psalm\DocComment;
use Psalm\Exception\DocblockParseException;
use Psalm\Issue\CodeIssue;
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
 * that was about to be checked against it, so the list is rebuilt from the compiled AST and the Blade-comment registry.
 *
 * Re-entrancy is guarded twice. Structurally, the re-emitted issue sits on the `.blade.php` path,
 * which is never a registry key, so the second pass declines on the lookup; the flag additionally
 * covers a future change that makes templates registry keys too.
 *
 * A taint finding can also land on ordinary application code that a template merely handed tainted
 * input to (#1519): the issue itself is already reported where Psalm found it, but its journey
 * still names the compiled shadow it passed through. That case re-emits through the same
 * `IssueBuffer::accepts()` route with only the journey rebuilt, never the issue's own location.
 *
 * @psalm-api
 *
 * @internal
 */
final class BladeIssueRemapHandler implements BeforeAddIssueInterface
{
    private static bool $remapping = false;

    private static bool $reportMixedIssues = false;

    /**
     * @var array<string, ShadowTarget|null> resolved shadows, negatives included: target() is asked
     *      once per issue and again per taint journey hop, over the same handful of paths, and both
     *      the registry and the template-source cache behind it are frozen before Psalm forks
     */
    private static array $targets = [];

    public static function init(bool $reportMixedIssues): void
    {
        self::$reportMixedIssues = $reportMixedIssues;
    }

    public static function reset(): void
    {
        self::$remapping = false;
        self::$reportMixedIssues = false;
        self::$targets = [];
    }

    #[\Override]
    public static function beforeAddIssue(BeforeAddIssueEvent $event): ?bool
    {
        if (self::$remapping) {
            return null;
        }

        $issue = $event->getIssue();

        if (ShadowRegistry::entryFor($issue->getFilePath()) instanceof ShadowEntry) {
            return self::remapShadowIssue($event, $issue);
        }

        // #1519: the sink is ordinary application code, but the taint reached it through a
        // template. Only the journey moves; the issue stays where Psalm found it.
        return self::remapJourney($issue);
    }

    private static function remapShadowIssue(BeforeAddIssueEvent $event, CodeIssue $issue): ?bool
    {
        $target = self::target($issue->getFilePath());

        if (!$target instanceof ShadowTarget) {
            return null;
        }

        // The resolver, not just the issue's own target: a taint journey hops between files, and
        // any of those hops can be another template's shadow.
        $relocated = ShadowIssueRelocator::relocate($issue, $target, self::target(...), self::$reportMixedIssues);

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
            IssueBuffer::accepts(
                $relocated,
                [
                    ...self::phpSuppressions($event),
                    ...($target->entry->suppressions[$relocated->code_location->getLineNumber()] ?? []),
                ],
            );
        } finally {
            self::$remapping = false;
        }

        return false;
    }

    /**
     * #1519: this branch runs on every issue that is NOT in a shadow — the overwhelming majority
     * of the run — so it stays an `instanceof` check plus a handful of array lookups on the miss
     * path, never a template read.
     */
    private static function remapJourney(CodeIssue $issue): ?bool
    {
        $taint = PsalmBridge::taintArguments($issue);

        if ($taint === null || !self::journeyCrossesShadow($taint['journey'])) {
            return null;
        }

        $remapped = JourneyRemapper::remap($taint['journey'], $taint['journey_text'], $issue->code_location, self::target(...));

        // Decline, never drop: an unmappable hop leaves Psalm's own (shadow-naming) journey
        // standing rather than losing a real finding.
        if ($remapped === null) {
            return null;
        }

        $rebuilt = ShadowIssueRelocator::relocateJourney($issue, $remapped);

        if (!$rebuilt instanceof CodeIssue) {
            return null;
        }

        self::$remapping = true;

        try {
            // `TaintFlowGraph::reportTaintedFlowOnce()` emits through `IssueBuffer::maybeAdd()` with an
            // EMPTY suppression list; matching it exactly is what keeps reportability identical to
            // the un-remapped issue Psalm would have emitted.
            IssueBuffer::accepts($rebuilt);
        } finally {
            self::$remapping = false;
        }

        return false;
    }

    /**
     * Pure array lookups — no template I/O on the hot path.
     *
     * @param list<array{location: ?CodeLocation, label: string, entry_path_type: string}> $journey
     */
    private static function journeyCrossesShadow(array $journey): bool
    {
        foreach ($journey as $step) {
            $location = PsalmBridge::stepLocation($step);

            if ($location instanceof CodeLocation && ShadowRegistry::entryFor($location->file_path) instanceof ShadowEntry) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function phpSuppressions(BeforeAddIssueEvent $event): array
    {
        $issue = $event->getIssue();
        $location = $issue->code_location;
        try {
            $statements = $event->getCodebase()->getStatementsForFile($issue->getFilePath());
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            // Unregistered or unreadable shadow: no suppressions provable, never a crash mid-emission.
            return [];
        }

        // The event omits Psalm's suppression list. Only enclosing statements can contribute it;
        // a sibling's docblock must never become a template-wide suppression. A type issue can
        // point inside the enclosing node's own docblock, before its first code token.
        $enclosing = (new NodeFinder())->find($statements, static fn(Node $node): bool
            => ($node instanceof Node\Stmt || $node instanceof Node\FunctionLike || $node instanceof Node\Expr\Match_)
            && ($node->getDocComment()?->getStartFilePos() ?? $node->getStartFilePos()) <= $location->raw_file_start
            && $node->getEndFilePos() >= $location->raw_file_end);
        $rules = [];
        foreach ($enclosing as $node) {
            if ($node instanceof Node\Expr\Match_) {
                \array_push($rules, ...self::matchSuppressions($node, $location));

                continue;
            }

            foreach ($node->getComments() as $comment) {
                if (!$comment instanceof Doc) {
                    continue;
                }

                try {
                    $parsed = DocComment::parsePreservingLength($comment);
                } catch (DocblockParseException) {
                    continue;
                }

                foreach ($parsed->tags['psalm-suppress'] ?? [] as $entry) {
                    foreach (DocComment::parseSuppressList($entry) as $rule) {
                        $rules[] = $rule;
                    }
                }
            }
        }

        return $rules;
    }

    /**
     * MatchAnalyzer analyzes a `match` as a desugared ternary with `RedundantCondition` and
     * `RedundantConditionGivenDocblockType` added to the analyzer's runtime suppression list, which
     * `BeforeAddIssueEvent` omits (upstream gap). Mirrored by AST shape so a template stays as quiet
     * as plain PHP (#1801). The window covers the arms only: the subject, and a `match` used as an
     * operand (the finding spans the whole expression), keep reporting. With no default arm
     * (`$last_arm->conds`) and a subject Psalm tracks (`$switch_var_id`, see {@see self::matchSubjectIsTracked()}),
     * the arm conditions are analyzed again after the window closes, so they keep reporting
     * `RedundantCondition`; the docblock sibling stays suppressed, as it is on plain PHP.
     *
     * @return list<string>
     */
    private static function matchSuppressions(Node\Expr\Match_ $match, CodeLocation $location): array
    {
        $contains = static fn(Node $node): bool => $node->getStartFilePos() <= $location->raw_file_start
            && $node->getEndFilePos() >= $location->raw_file_end;
        $hasDefault = false;
        $inReanalyzedCondition = false;
        $inArm = false;

        foreach ($match->arms as $arm) {
            if ($arm->conds === null) {
                $hasDefault = true;
            }

            if (!$contains($arm)) {
                continue;
            }

            $inArm = true;

            foreach ($arm->conds ?? [] as $cond) {
                $inReanalyzedCondition = $inReanalyzedCondition || $contains($cond);
            }
        }

        if (!$inArm) {
            return [];
        }

        if (!$hasDefault && self::matchSubjectIsTracked($match->cond) && $inReanalyzedCondition) {
            return ['RedundantConditionGivenDocblockType'];
        }

        return ['RedundantCondition', 'RedundantConditionGivenDocblockType'];
    }

    /**
     * Whether MatchAnalyzer gives the subject a `$switch_var_id`, mirroring its checks verbatim,
     * including their case-sensitivity (`TRUE` and `Foo::CLASS` are tracked): an untracked subject
     * skips the post-window re-analysis. A subject with a variable id (`$x`, `$x->y`, `Foo::class`)
     * is tracked outright; the shapes below are the ones with none that stay untracked.
     */
    private static function matchSubjectIsTracked(Node\Expr $subject): bool
    {
        if ($subject instanceof Node\Expr\ConstFetch) {
            return $subject->name->toString() !== 'true';
        }

        if ($subject instanceof Node\Expr\ClassConstFetch) {
            return $subject->class instanceof Node\Name
                || !$subject->name instanceof Node\Identifier
                || $subject->name->toString() !== 'class';
        }

        if ($subject instanceof Node\Expr\FuncCall && $subject->name instanceof Node\Name) {
            // A plain-variable argument is not rewritten into a temporary, so no id is assigned.
            return !\in_array($subject->name->getParts(), [['get_class'], ['gettype'], ['get_debug_type'], ['count'], ['sizeof']], true)
                || $subject->getArgs() === []
                || !$subject->getArgs()[0]->value instanceof Node\Expr\Variable;
        }

        return true;
    }

    /** Null for any path that is not a registered shadow with a readable template. */
    private static function target(string $shadowPath): ?ShadowTarget
    {
        if (\array_key_exists($shadowPath, self::$targets)) {
            return self::$targets[$shadowPath];
        }

        return self::$targets[$shadowPath] = self::resolveTarget($shadowPath);
    }

    private static function resolveTarget(string $shadowPath): ?ShadowTarget
    {
        $entry = ShadowRegistry::entryFor($shadowPath);

        if (!$entry instanceof ShadowEntry) {
            return null;
        }

        $templateSource = ShadowRegistry::templateSource($entry->templatePath);

        if ($templateSource === null) {
            return null;
        }

        return new ShadowTarget(
            $entry,
            $templateSource,
            Config::getInstance()->shortenFileName($entry->templatePath),
            PreludeBuilder::isComponentView($templateSource),
            // Falling back to the live bytes only when boot recorded nothing: no worse than having
            // no snapshot at all, and a wrong prefix costs a no-op strip, never a wrong one.
            ShadowRegistry::markerPrefixFor($entry->templatePath) ?? MarkerComment::prefixFor($templateSource),
        );
    }
}
