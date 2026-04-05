<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Rules;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use Psalm\CodeLocation;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\Codebase\TaintFlowGraph;
use Psalm\Internal\DataFlow\DataFlowNode;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Type\TaintKind;
use Psalm\Type\Union;

/**
 * Detects timing-unsafe string comparisons involving secrets (CWE-208).
 *
 * When secrets (values tainted with user_secret or system_secret) are compared
 * using ===, ==, !==, !=, strcmp(), or strcasecmp(), an attacker can determine
 * the correct value character-by-character by measuring response time differences.
 * Use hash_equals() for constant-time comparison instead.
 *
 * This handler adds taint sinks at comparison operators and timing-unsafe
 * functions. When a secret-tainted value flows into these sinks, Psalm emits
 * TaintedUserSecret or TaintedSystemSecret.
 *
 * @see https://cwe.mitre.org/data/definitions/208.html
 */
final class TimingUnsafeComparisonHandler implements AfterExpressionAnalysisInterface
{
    /** Taint mask for secrets that require constant-time comparison */
    private const SECRET_TAINTS = TaintKind::USER_SECRET | TaintKind::SYSTEM_SECRET;

    /** Functions that compare strings in a timing-unsafe manner */
    private const TIMING_UNSAFE_FUNCTIONS = ['strcmp', 'strcasecmp'];

    /** @inheritDoc */
    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();
        $source = $event->getStatementsSource();

        if (!$source instanceof StatementsAnalyzer) {
            return null;
        }

        $taintFlowGraph = $source->taint_flow_graph;

        if (!$taintFlowGraph instanceof \Psalm\Internal\Codebase\TaintFlowGraph) {
            return null;
        }

        if ($expr instanceof BinaryOp\Identical
            || $expr instanceof BinaryOp\Equal
            || $expr instanceof BinaryOp\NotIdentical
            || $expr instanceof BinaryOp\NotEqual
        ) {
            self::addSinksForOperands(
                $source,
                $taintFlowGraph,
                $expr,
                $source->getNodeTypeProvider()->getType($expr->left),
                $source->getNodeTypeProvider()->getType($expr->right),
            );

            return null;
        }

        // strcmp() and strcasecmp() are also timing-unsafe — they compare
        // character-by-character and the return value reveals partial ordering
        if ($expr instanceof FuncCall
            && $expr->name instanceof Name
            && \in_array($expr->name->toLowerString(), self::TIMING_UNSAFE_FUNCTIONS, true)
            && \count($expr->args) >= 2
            && $expr->args[0] instanceof Arg
            && $expr->args[1] instanceof Arg
        ) {
            self::addSinksForOperands(
                $source,
                $taintFlowGraph,
                $expr,
                $source->getNodeTypeProvider()->getType($expr->args[0]->value),
                $source->getNodeTypeProvider()->getType($expr->args[1]->value),
            );
        }

        return null;
    }

    /**
     * Add taint sinks for both operands of a timing-unsafe comparison.
     *
     * Each operand's data flow parent nodes are connected to a new sink node
     * that matches user_secret and system_secret taints. If either operand
     * carries secret taint, Psalm's taint resolution will report the issue.
     *
     * @psalm-external-mutation-free
     */
    private static function addSinksForOperands(
        StatementsAnalyzer $source,
        TaintFlowGraph $graph,
        Expr $comparisonExpr,
        ?Union $leftType,
        ?Union $rightType,
    ): void {
        $codeLocation = new CodeLocation($source, $comparisonExpr);
        $locationId = \strtolower($codeLocation->file_name)
            . ':' . $codeLocation->raw_file_start
            . '-' . $codeLocation->raw_file_end;

        self::addSinkForType($graph, $leftType, 'timing-comparison-left', $locationId, $codeLocation);
        self::addSinkForType($graph, $rightType, 'timing-comparison-right', $locationId, $codeLocation);
    }

    /**
     * Create a single taint sink for an operand and connect all its data flow
     * parent nodes to it. One sink per operand side avoids duplicate reports
     * and keeps the taint graph compact.
     *
     * The sink matches USER_SECRET | SYSTEM_SECRET, so only secret-tainted
     * data triggers an issue — ordinary input taint is not affected.
     *
     * @psalm-external-mutation-free
     */
    private static function addSinkForType(
        TaintFlowGraph $graph,
        ?Union $type,
        string $sinkLabel,
        string $locationId,
        CodeLocation $codeLocation,
    ): void {
        if (!$type instanceof \Psalm\Type\Union || $type->parent_nodes === []) {
            return;
        }

        $sinkId = $sinkLabel . '-' . $locationId;

        $sink = DataFlowNode::make(
            $sinkId,
            $sinkLabel,
            $codeLocation,
            null,
            self::SECRET_TAINTS,
        );

        $graph->addSink($sink);

        foreach ($type->parent_nodes as $parentNode) {
            $graph->addPath($parentNode, $sink, 'timing-comparison');
        }
    }

}
