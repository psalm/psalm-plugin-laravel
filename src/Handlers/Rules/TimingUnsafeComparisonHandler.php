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
use PhpParser\Node\Identifier;
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
 * Comparing a secret (user_secret/system_secret taint) with ===, ==, !==, !=, <, <=, >,
 * >=, <=>, strcmp(), strcasecmp(), strncmp(), strncasecmp(), or substr_compare() leaks the
 * value character-by-character via response-time differences. Use hash_equals().
 *
 * The handler adds taint sinks at those operators/functions; a secret-tainted operand
 * flowing in makes Psalm emit TaintedUserSecret/TaintedSystemSecret.
 *
 * Upstream limitation: Psalm 7 hardcodes the per-kind message in
 * TaintFlowGraph::connectSinksAndSources(), so until vimeo/psalm#11762 lands the text
 * is the default "Detected tainted user secret leaking" rather than CWE-208-specific.
 * The flow still pinpoints the comparison site correctly.
 *
 * @see https://cwe.mitre.org/data/definitions/208.html
 * @see https://github.com/vimeo/psalm/issues/11762
 */
final class TimingUnsafeComparisonHandler implements AfterExpressionAnalysisInterface
{
    /** Taint mask for secrets that require constant-time comparison */
    private const SECRET_TAINTS = TaintKind::USER_SECRET | TaintKind::SYSTEM_SECRET;

    /**
     * Timing-unsafe string-comparison functions (all C-level byte-by-byte), mapped to the two
     * string comparands as `[paramName, canonicalPosition]`. Resolving by name AND position lets
     * us find the secret operand even when the call uses named arguments in any order (e.g.
     * `strncmp(length: 8, string1: $a, string2: $secret)` puts the secret at syntactic args[2]).
     *
     * @var array<string, array{array{string, int}, array{string, int}}>
     */
    private const TIMING_UNSAFE_FUNCTIONS = [
        'strcmp' => [['string1', 0], ['string2', 1]],
        'strcasecmp' => [['string1', 0], ['string2', 1]],
        'strncmp' => [['string1', 0], ['string2', 1]],
        'strncasecmp' => [['string1', 0], ['string2', 1]],
        'substr_compare' => [['haystack', 0], ['needle', 1]],
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

        // TaintFlowGraph exists only under --taint-analysis; bail early so normal runs pay ~zero.
        if (!$taintFlowGraph instanceof TaintFlowGraph) {
            return null;
        }

        // BinaryOp gate first: this method runs for every expression, so the cheap check
        // skips the FuncCall branch for the BinaryOp majority and the narrower instanceofs
        // for everything else. `<=>` and the relational operators (<, <=, >, >=) count too —
        // each leaks the secret's lexicographic ordering byte-by-byte just like strcmp().
        if ($expr instanceof BinaryOp) {
            if ($expr instanceof BinaryOp\Identical
                || $expr instanceof BinaryOp\Equal
                || $expr instanceof BinaryOp\NotIdentical
                || $expr instanceof BinaryOp\NotEqual
                || $expr instanceof BinaryOp\Spaceship
                || $expr instanceof BinaryOp\Smaller
                || $expr instanceof BinaryOp\SmallerOrEqual
                || $expr instanceof BinaryOp\Greater
                || $expr instanceof BinaryOp\GreaterOrEqual
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

        // strcmp() and variants leak partial ordering even when the caller only checks `=== 0`.
        // `count(args) >= 2` precedes `toLowerString()` so unrelated calls skip the lowercasing.
        if ($expr instanceof FuncCall
            && \count($expr->args) >= 2
            && $expr->name instanceof Name
        ) {
            $operandSpecs = self::TIMING_UNSAFE_FUNCTIONS[$expr->name->toLowerString()] ?? null;

            if ($operandSpecs !== null) {
                [$leftSpec, $rightSpec] = $operandSpecs;
                $leftExpr = self::resolveArgument($expr->args, $leftSpec[0], $leftSpec[1]);
                $rightExpr = self::resolveArgument($expr->args, $rightSpec[0], $rightSpec[1]);

                if ($leftExpr instanceof Expr && $rightExpr instanceof Expr) {
                    self::addSinksForOperands(
                        $source,
                        $taintFlowGraph,
                        $expr,
                        $leftExpr,
                        $rightExpr,
                        $source->getNodeTypeProvider()->getType($leftExpr),
                        $source->getNodeTypeProvider()->getType($rightExpr),
                    );
                }
            }
        }

        return null;
    }

    /**
     * Resolve a function argument by parameter name first, then by positional index, so a watched
     * comparand is found regardless of whether the call passes it positionally or as a named
     * argument. Returns null when the argument is absent or unpacked (`...$args`), where the
     * position can no longer be determined statically.
     *
     * @param array<Arg|\PhpParser\Node\VariadicPlaceholder> $args
     *
     * @psalm-mutation-free
     */
    private static function resolveArgument(array $args, string $name, int $position): ?Expr
    {
        // A named argument matching the parameter wins outright (it may appear in any order).
        foreach ($args as $arg) {
            if ($arg instanceof Arg && $arg->name instanceof Identifier && $arg->name->name === $name) {
                return $arg->value;
            }
        }

        // Otherwise count only positional (unnamed) arguments in source order. PHP requires
        // positional args before named ones, so the n-th unnamed arg is at parameter position n.
        $index = 0;
        foreach ($args as $arg) {
            if (!$arg instanceof Arg || $arg->name instanceof \PhpParser\Node\Identifier) {
                continue;
            }

            // An unpacked argument shifts every later position by an unknown amount — give up.
            if ($arg->unpack) {
                return null;
            }

            if ($index === $position) {
                return $arg->value;
            }

            $index++;
        }

        return null;
    }

    /**
     * Add taint sinks for both operands of a timing-unsafe comparison: each operand's data
     * flow parents connect to a sink matching user_secret|system_secret, so a secret-tainted
     * operand gets reported.
     *
     * A literal operand (scalar / null / true / false) is the known half of the comparison and
     * leaks nothing, so shapes like `$secret === null` or `$secret === ''` are skipped to avoid
     * false positives on idiomatic checks.
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
     * Whether the operand is a developer-written constant with no runtime data flow. Accepted:
     * int/float/interpolation-free string scalars (heredoc/nowdoc without `{$x}` included), magic
     * constants, `null`/`true`/`false`, unary `+`/`-` on a literal, and concat of literals on both
     * sides.
     *
     * Class constants (`Foo::BAR`) and enum cases are NOT exempted: an attacker-controlled
     * indirection could resolve to the same constant, so err on a rare FP over disarming the rule.
     *
     * Matching by AST shape (not inferred type) stays robust against Psalm narrowing `string $x`
     * to `''` after a prior check — those still carry real data flow and must be flagged.
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
     * Create one sink per operand side (avoids duplicate reports, keeps the graph compact) and
     * connect all the operand's data flow parents to it. The sink matches USER_SECRET|SYSTEM_SECRET,
     * so only secret-tainted data triggers an issue — ordinary input taint is unaffected.
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
