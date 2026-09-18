<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Internal\Codebase\TaintFlowGraph;
use Psalm\Issue\CodeIssue;
use Psalm\Issue\TaintedInput;

/**
 * The single place the Blade pipeline touches Psalm internals that differ between Psalm 6 and
 * Psalm 7. Every other Blade class works in terms of these methods, so porting the pipeline to the
 * Psalm 7 line is a rewrite of this file rather than a hunt through the package.
 *
 * Each method carries the Psalm 6 file:line it was read off. Citations are against the Psalm the
 * branch requires (`vendor/vimeo/psalm`, 6.17.x); re-read them before trusting one on another line.
 *
 * @internal
 */
final class PsalmBridge
{
    /**
     * Whether Psalm is running taint analysis.
     *
     * Psalm 6 builds `Codebase::$taint_flow_graph` only under `--taint-analysis`
     * (`vendor/vimeo/psalm/src/Psalm/Codebase.php:173`, nullable and null by default), and in that
     * mode `IssueBuffer::add()` discards every non-`Tainted*` issue. Psalm 7 runs taint by default
     * and reports both kinds from one run, so there the answer is not a mode at all.
     */
    public static function isTaintRun(Codebase $codebase): bool
    {
        return $codebase->taint_flow_graph instanceof TaintFlowGraph;
    }

    /**
     * The two extra constructor arguments of a taint issue, keyed by PARAMETER name, or null when
     * the issue is not one.
     *
     * `Psalm\Issue\TaintedInput` (`vendor/vimeo/psalm/src/Psalm/Issue/TaintedInput.php:16-26`) is the
     * base of every `Tainted*` class and promotes both to public readonly properties, so the
     * parameter names double as the property names a reflective rebuild reads.
     *
     * @return array{journey: list<array{location: ?CodeLocation, label: string, entry_path_type: string}>, journey_text: string}|null
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
     * (`vendor/vimeo/psalm/src/Psalm/Internal/Codebase/TaintFlowGraph.php:184-202`): the `location`
     * key mirrors `DataFlowNode::$code_location`, which is null for nodes that stand for a symbol
     * rather than an expression (a stubbed taint source, for one).
     *
     * @param array{location: ?CodeLocation, label: string, entry_path_type: string} $step
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
     * (`vendor/vimeo/psalm/src/Psalm/CodeLocation.php:408-411`), assembled by
     * `TaintFlowGraph::getPredecessorPath()` and `getSuccessorPath()` (same file, lines 119-177).
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
     */
    public static function locationSummaryPattern(string $fileName): string
    {
        return '/' . \preg_quote($fileName, '/') . ':(\d++):(\d++)/';
    }
}
