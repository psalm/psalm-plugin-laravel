<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Rules;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\UnaryPlus;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
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
 * using ===, ==, !==, !=, <=>, strcmp(), strcasecmp(), strncmp(), strncasecmp(),
 * or substr_compare(), an attacker can determine the correct value
 * character-by-character by measuring response time differences. Use
 * hash_equals() for constant-time comparison instead.
 *
 * This handler adds taint sinks at comparison operators and timing-unsafe
 * functions. When a secret-tainted value flows into these sinks, Psalm emits
 * TaintedUserSecret or TaintedSystemSecret.
 *
 * Upstream limitation: Psalm 7 hardcodes the issue message per taint kind in
 * TaintFlowGraph::connectSinksAndSources(). Until vimeo/psalm#11762 lands,
 * the emitted message is the default "Detected tainted user secret leaking"
 * rather than a CWE-208-specific one. The taint flow itself, however, still
 * correctly pinpoints the timing-unsafe comparison site.
 *
 * @see https://cwe.mitre.org/data/definitions/208.html
 * @see https://github.com/vimeo/psalm/issues/11762
 */
final class TimingUnsafeComparisonHandler implements AfterExpressionAnalysisInterface
{
    /** Taint mask for secrets that require constant-time comparison */
    private const SECRET_TAINTS = TaintKind::USER_SECRET | TaintKind::SYSTEM_SECRET;

    /** Functions that compare strings in a timing-unsafe manner (all C-level byte-by-byte). */
    private const TIMING_UNSAFE_FUNCTIONS = [
        'strcmp',
        'strcasecmp',
        'strncmp',
        'strncasecmp',
        'substr_compare',
    ];

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

        // TaintFlowGraph is only present during --taint-analysis runs.
        // Without it, the handler exits immediately to keep normal-analysis overhead near zero.
        if (!$taintFlowGraph instanceof TaintFlowGraph) {
            return null;
        }

        // Short-circuit on BinaryOp first: every analyzed expression hits this method, but
        // only BinaryOp and FuncCall expressions are relevant. The `instanceof BinaryOp`
        // gate skips the FuncCall branch for the BinaryOp majority and skips five
        // narrower instanceof checks for everything else (vars, calls, literals, etc.).
        // `<=>` is included: it compares byte-by-byte and its -1/0/1 result leaks
        // ordering of the secret just as `strcmp()` does.
        if ($expr instanceof BinaryOp) {
            if ($expr instanceof BinaryOp\Identical
                || $expr instanceof BinaryOp\Equal
                || $expr instanceof BinaryOp\NotIdentical
                || $expr instanceof BinaryOp\NotEqual
                || $expr instanceof BinaryOp\Spaceship
            ) {
                self::addSinksForOperands(
                    $source,
                    $taintFlowGraph,
                    $expr,
                    $expr->left,
                    $expr->right,
                    $source->getNodeTypeProvider()->getType($expr->left),
                    $source->getNodeTypeProvider()->getType($expr->right),
                );
            }

            return null;
        }

        // strcmp() and variants are also timing-unsafe — they compare character-by-character
        // and the return value reveals partial ordering even when the caller only checks `=== 0`.
        // `\count(args) >= 2` runs before `toLowerString()` so unrelated nullary/unary calls
        // (microtime(), count(), etc.) don't pay for the lowercase allocation.
        if ($expr instanceof FuncCall
            && \count($expr->args) >= 2
            && $expr->name instanceof Name
            && \in_array($expr->name->toLowerString(), self::TIMING_UNSAFE_FUNCTIONS, true)
            && $expr->args[0] instanceof Arg
            && $expr->args[1] instanceof Arg
        ) {
            self::addSinksForOperands(
                $source,
                $taintFlowGraph,
                $expr,
                $expr->args[0]->value,
                $expr->args[1]->value,
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
     * Operands that are literal scalars / null / true / false carry no
     * attacker-derived content. Comparing a secret to a known literal never
     * leaks information bit-by-bit (the literal IS the known half of the
     * comparison), so we skip those defensive shapes (e.g. `$secret === null`,
     * `$secret === ''`) to avoid false positives on idiomatic checks.
     */
    private static function addSinksForOperands(
        StatementsAnalyzer $source,
        TaintFlowGraph $graph,
        Expr $comparisonExpr,
        Expr $leftExpr,
        Expr $rightExpr,
        ?Union $leftType,
        ?Union $rightType,
    ): void {
        if (self::isLiteralOperand($leftExpr) || self::isLiteralOperand($rightExpr)) {
            return;
        }

        $codeLocation = new CodeLocation($source, $comparisonExpr);
        $locationId = \strtolower($codeLocation->file_name)
            . ':' . $codeLocation->raw_file_start
            . '-' . $codeLocation->raw_file_end;

        self::addSinkForType($graph, $leftType, 'timing-comparison-left', $locationId, $codeLocation);
        self::addSinkForType($graph, $rightType, 'timing-comparison-right', $locationId, $codeLocation);
    }

    /**
     * Whether the operand is a constant the developer wrote directly (no
     * runtime data flow). Accepted shapes:
     *
     *  - Integer / float / interpolation-free string scalars
     *    (heredoc/nowdoc without `{$x}` parses as `Scalar\String_`, covered)
     *  - Magic constants (`__FILE__`, `__LINE__`, `__CLASS__`, ...)
     *  - `null` / `true` / `false`
     *  - Unary `+` / `-` applied to a literal (e.g. `=== -1`)
     *  - String concat of literals on both sides (e.g. `'sentinel-' . self::SUFFIX`
     *    only matches when SUFFIX is also a literal; class constants are NOT
     *    treated as literals on purpose — see note below)
     *
     * Class constants (`Foo::BAR`) and enum cases (`Status::Active`) are
     * intentionally NOT exempted: an attacker-controlled indirection could
     * resolve to the same constant at runtime, and the rule should err on
     * flagging a rare FP rather than silently disarming for an entire common
     * pattern.
     *
     * Matching by AST shape (not by inferred type) keeps the check robust
     * against Psalm narrowing `string $x` to `''` after a prior check — those
     * still carry real data flow and must be flagged.
     */
    private static function isLiteralOperand(Expr $expr): bool
    {
        if ($expr instanceof Scalar\LNumber
            || $expr instanceof Scalar\DNumber
            || $expr instanceof Scalar\String_
            || $expr instanceof Scalar\MagicConst
        ) {
            return true;
        }

        if ($expr instanceof ConstFetch) {
            $name = $expr->name->toLowerString();

            return in_array($name, ['null', 'true', 'false'], true);
        }

        if ($expr instanceof UnaryMinus || $expr instanceof UnaryPlus) {
            return self::isLiteralOperand($expr->expr);
        }

        if ($expr instanceof BinaryOp\Concat) {
            return self::isLiteralOperand($expr->left) && self::isLiteralOperand($expr->right);
        }

        return false;
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
        if (!$type instanceof Union || $type->parent_nodes === []) {
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
