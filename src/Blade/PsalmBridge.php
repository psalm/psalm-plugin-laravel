<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Psalm\CodeLocation;
use Psalm\Issue\CodeIssue;
use Psalm\Issue\TaintedInput;

/**
 * The single place the Blade pipeline touches Psalm internals that differ between Psalm 6 and
 * Psalm 7. Every other Blade class works in terms of these methods, so porting the pipeline to the
 * other Psalm line is a rewrite of this file rather than a hunt through the package.
 *
 * Each method carries the file:line it was read off. Citations are against the Psalm the branch
 * requires (`vendor/vimeo/psalm`, 7.0.0-beta21); re-read them before trusting one on another line.
 *
 * No taint-mode probe lives here. Psalm 6 runs one mode per invocation and `IssueBuffer::add()`
 * discards every non-`Tainted*` issue under `--taint-analysis`, which is why the 3.x pipeline asks.
 * Psalm 7 runs taint by default (`Config::$run_taint_analysis = true`, `Config.php:409`) and emits
 * both kinds from one run — `IssueBuffer::add()` only ever drops `Tainted*` issues, and only when
 * taint is off (`IssueBuffer.php:168-174`) — so on this line there is no mode to short-circuit on.
 *
 * @internal
 *
 * @psalm-immutable
 */
final class PsalmBridge
{
    /**
     * The two extra constructor arguments of a taint issue, keyed by PARAMETER name, or null when
     * the issue is not one.
     *
     * `Psalm\Issue\TaintedInput` (`vendor/vimeo/psalm/src/Psalm/Issue/TaintedInput.php:13-29`) is the
     * base of every `Tainted*` class and promotes both to public readonly properties, so the
     * parameter names double as the property names a reflective rebuild reads.
     *
     * @return array{journey: list<array{location: ?CodeLocation, label: string, entry_path_type: string}>, journey_text: string}|null
     *
     * @psalm-mutation-free
     */
    public static function taintArguments(CodeIssue $issue): ?array
    {
        if (!$issue instanceof TaintedInput) {
            return null;
        }

        return ['journey' => $issue->journey, 'journey_text' => $issue->journey_text];
    }

    /**
     * The location of one journey step, or null for a step that has none.
     *
     * Step shape is fixed by `TaintFlowGraph::getIssueTrace()`
     * (`vendor/vimeo/psalm/src/Psalm/Internal/Codebase/TaintFlowGraph.php:217-236`): the `location`
     * key mirrors `DataFlowNode::$code_location`, which is null for nodes that stand for a symbol
     * rather than an expression (a stubbed taint source, for one).
     *
     * @param array{location: ?CodeLocation, label: string, entry_path_type: string} $step
     *
     * @psalm-pure
     */
    public static function stepLocation(array $step): ?CodeLocation
    {
        return $step['location'];
    }

    /**
     * The same step, repositioned.
     *
     * @param array{location: ?CodeLocation, label: string, entry_path_type: string} $step
     *
     * @return array{location: ?CodeLocation, label: string, entry_path_type: string}
     *
     * @psalm-pure
     */
    public static function withStepLocation(array $step, CodeLocation $location): array
    {
        $step['location'] = $location;

        return $step;
    }

    /**
     * One location as it is spelled inside `journey_text`.
     *
     * `journey_text` is a ` -> `-joined chain of `label (file_name:line:column)` descriptors, the
     * `file_name:line:column` half being `CodeLocation::getShortSummary()`
     * (`vendor/vimeo/psalm/src/Psalm/CodeLocation.php:434-437`), assembled by
     * `TaintFlowGraph::getPredecessorPath()` and `getSuccessorPath()` (same file, lines 148-211).
     *
     * @psalm-pure
     */
    public static function locationSummary(string $fileName, int $line, int $column): string
    {
        return $fileName . ':' . $line . ':' . $column;
    }

    /**
     * Matches every {@see self::locationSummary()} for one file inside a `journey_text`, capturing
     * line and column.
     *
     * A rewrite has to go through the text: `journey_text` also describes the sink-side nodes,
     * which the journey array stops short of, so it cannot be regenerated from that array.
     *
     * @return non-empty-string
     *
     * @psalm-pure
     */
    public static function locationSummaryPattern(string $fileName): string
    {
        return '/' . \preg_quote($fileName, '/') . ':(\d++):(\d++)/';
    }
}
