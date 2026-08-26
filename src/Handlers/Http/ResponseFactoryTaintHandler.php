<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Http;

use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\Facades\Response;
use PhpParser\Node;
use PhpParser\Node\ClosureUse;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\Relative;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use Psalm\CodeLocation;
use Psalm\Issue\TaintedHtml;
use Psalm\Plugin\EventHandler\BeforeAddIssueInterface;
use Psalm\Plugin\EventHandler\Event\BeforeAddIssueEvent;

/**
 * Suppresses the `TaintedHtml` finding on a `ResponseFactory::make()` or `Illuminate\Http\Response`
 * constructor call when the call's literal headers prove the browser downloads the response instead
 * of rendering it.
 *
 * The exemption is applied when the issue is emitted and the taint graph is never edited, so no
 * decision made here can reach another flow. Nothing is carried over from the analysis phase
 * either: every fact is re-derived from the issue plus a fresh look at the sink's file. Rationale
 * for both, and the dead end they replace, in `docs/contributing/decisions.md` under "Call-site
 * sink exemptions are applied at issue emission" (vimeo/psalm#11924).
 *
 * The proof stays deliberately syntactic, never control-flow aware. The headers argument must
 * either be an array literal at the call site, or a local variable proven to hold exactly one
 * array literal: assigned once, in the same function-like, before the call, never reassigned,
 * mutated, passed elsewhere, or captured by a nested closure, and the scope must not touch a
 * variable-variable or `extract()`/`compact()`/`get_defined_vars()`. Each such miss fails toward a
 * retained finding, never a dropped one.
 *
 * ACCEPTED LIMITATION: every `make()` call in a project shares one sink node, and
 * `TaintFlowGraph::getChildNodes()` walks it once, so its `visited_source_ids` set already discards
 * every flow into it longer than the first one found. Exempting that first flow leaves the sink
 * reporting nothing rather than reporting one of its flows. The longer flow is lost with or without
 * this handler; pinned by `TaintedHtmlResponseFactoryMakeSharedSinkKnownLimitation.phpt`.
 *
 * Every private helper below is free of side effects, but the purity annotations follow what Psalm
 * can verify rather than what is true: helpers that reach for a call's arguments or walk the AST
 * with `NodeFinder`/`NodeTraverser` carry none, because those are impure in Psalm's own stubs.
 */
final class ResponseFactoryTaintHandler implements BeforeAddIssueInterface
{
    /**
     * Journey-tail labels that mark the response factory's html content sink.
     *
     * Derived empirically on Psalm 7.0.0-beta19 by dumping `TaintedInput::$journey` tails for the
     * concrete, contract, facade, root-alias, `response()` helper, and `Illuminate\Http\Response`
     * constructor call routes. Two properties of that dump shape this list:
     *
     * - The tail is the ARGUMENT node feeding the sink, one node short of the sink itself, and its
     *   label is `call to <declaring class>::<method>`. The constructor route confirmed the same
     *   shape: `call to Illuminate\Http\Response::__construct`, with the location spanning the
     *   `$content` argument exactly as the `make()` labels span theirs.
     * - The root `\Response` alias yields the unqualified label `call to Response::make`. It is left
     *   out, so that alias keeps the sink exactly as it did under the previous receiver check.
     *
     * When the sink's file is not reportable Psalm falls back to the SOURCE location and the tail
     * carries the bare sink id instead (`...ResponseFactory::make#1`, no `call to ` prefix). Those
     * fail this match and retain the finding.
     */
    private const SINK_CALL_LABELS = [
        'call to ' . ResponseFactory::class . '::make',
        'call to ' . ResponseFactoryContract::class . '::make',
        'call to ' . Response::class . '::make',
        'call to ' . HttpResponse::class . '::__construct',
    ];

    /**
     * Symfony's header-name folding, copied from `HeaderBag::UPPER` / `HeaderBag::LOWER`. Every
     * `ResponseHeaderBag` write runs the name through `strtr()` with this pair, so `_` collapses
     * onto `-` and two spellings of one header collide.
     */
    private const HEADER_NAME_UPPER = '_ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    private const HEADER_NAME_LOWER = '-abcdefghijklmnopqrstuvwxyz';

    #[\Override]
    public static function beforeAddIssue(BeforeAddIssueEvent $event): ?bool
    {
        $issue = $event->getIssue();

        if (!$issue instanceof TaintedHtml) {
            return null;
        }

        $content = self::contentLocationOfMakeSink($issue->journey);

        if (!$content instanceof CodeLocation) {
            return null;
        }

        try {
            $stmts = $event->getCodebase()->getStatementsForFile($content->file_path);
        } catch (\Throwable) {
            // An unreadable or unparsable sink file proves nothing, so the finding stands.
            return null;
        }

        $call = self::findMakeCallWithContentAt($stmts, $content->getSelectionBounds());

        return $call !== null && self::provesLiteralAttachment($stmts, $call) ? false : null;
    }

    /**
     * @param list<array{location: ?CodeLocation, label: string, entry_path_type: string}> $journey
     *
     * @psalm-pure
     */
    private static function contentLocationOfMakeSink(array $journey): ?CodeLocation
    {
        $tail = $journey === [] ? null : $journey[\count($journey) - 1];

        if ($tail === null || !\in_array($tail['label'], self::SINK_CALL_LABELS, true)) {
            return null;
        }

        return $tail['location'];
    }

    /**
     * Locates the `make()` or `new Response()` call whose content argument spans exactly `$bounds`.
     *
     * The receiver and, for `New_`, the class name are deliberately not inspected: `parent::make()`,
     * `$this->make()`, `static::make()`, and any `New_` all reach the stubbed sink, and only Psalm's
     * own resolution — already proven by the journey label — can tell them apart. More than one
     * match is treated as unproven.
     *
     * @param list<Node\Stmt> $stmts
     * @param array{0: int, 1: int} $bounds
     */
    private static function findMakeCallWithContentAt(array $stmts, array $bounds): MethodCall|StaticCall|New_|null
    {
        /** @psalm-var list<MethodCall|StaticCall|New_> $calls */
        $calls = (new NodeFinder())->find($stmts, static function (Node $node) use ($bounds): bool {
            if ($node instanceof New_) {
                // getArgs() throws on a first-class callable (`new A(...)` is not valid syntax, but
                // an anonymous class body could still reach here through a nested closure).
                if ($node->isFirstClassCallable()) {
                    return false;
                }

                $args = $node->getArgs();

                return $args !== []
                    && $args[0]->value->getStartFilePos() === $bounds[0]
                    && $args[0]->value->getEndFilePos() + 1 === $bounds[1];
            }

            if ((!$node instanceof MethodCall && !$node instanceof StaticCall)
                || !$node->name instanceof Identifier
                || \strtolower($node->name->name) !== 'make'
                // getArgs() throws on a first-class callable (`make(...)`).
                || $node->isFirstClassCallable()
            ) {
                return false;
            }

            $args = $node->getArgs();

            return $args !== []
                && $args[0]->value->getStartFilePos() === $bounds[0]
                && $args[0]->value->getEndFilePos() + 1 === $bounds[1];
        });

        return \count($calls) === 1 ? $calls[0] : null;
    }

    /**
     * Named arguments are rejected rather than resolved: an in-order named argument carries the
     * same content span as a positional one, so accepting the span alone would silently exempt it.
     *
     * Testing the LAST argument covers all three positions. PHP allows a named or unpacked argument
     * only after every positional one, so a third argument that is neither proves the first two are
     * positional too. The unit tests pin each position rather than the check that catches it.
     *
     * @param list<Node\Stmt> $stmts
     */
    private static function provesLiteralAttachment(array $stmts, MethodCall|StaticCall|New_ $call): bool
    {
        $args = $call->getArgs();

        if (\count($args) !== 3 || $args[2]->name !== null || $args[2]->unpack) {
            return false;
        }

        $headers = $args[2]->value;

        if ($headers instanceof Array_) {
            return self::provesAttachment($headers);
        }

        if (!$headers instanceof Variable || !\is_string($headers->name)) {
            return false;
        }

        $resolved = self::resolveVariableHeaders($stmts, $call, $headers);

        return $resolved instanceof Array_ && self::provesAttachment($resolved);
    }

    /**
     * Resolves `$headersVar` to the single array literal assigned to it, when the enclosing
     * function-like proves that assignment is the only write and the call's argument is the only
     * other read.
     *
     * Deliberately syntactic, not control-flow aware: an assignment inside a conditional branch
     * still counts as long as it is textually the only one and precedes the call, exactly like a
     * reassignment or a read anywhere else disqualifies it regardless of reachability. This mirrors
     * the literal-array proof it feeds: both fail toward a retained finding on anything they cannot
     * fully account for syntactically.
     *
     * @param list<Node\Stmt> $stmts
     */
    private static function resolveVariableHeaders(array $stmts, MethodCall|StaticCall|New_ $call, Variable $headersVar): ?Array_
    {
        $name = $headersVar->name;

        if (!\is_string($name)) {
            return null;
        }

        $scope = self::findEnclosingFunctionLike($stmts, $call);

        if ($scope === null || self::hasDynamicVariableAccess($scope)) {
            return null;
        }

        // Parent pointers are the only way to tell "the assignment's LHS" from any other read of
        // the same name without re-implementing a scope walk; ParentConnectingVisitor mutates node
        // attributes, but re-running it is idempotent, so this stays safe to call once per issue.
        (new NodeTraverser(new ParentConnectingVisitor()))->traverse([$scope]);

        /** @var list<Variable|ClosureUse> $occurrences */
        $occurrences = (new NodeFinder())->find($scope, static fn(Node $node): bool => ($node instanceof Variable && \is_string($node->name) && $node->name === $name)
            || ($node instanceof ClosureUse && $node->var->name === $name));

        if (\count($occurrences) !== 2) {
            return null;
        }

        $assignedArray = null;

        foreach ($occurrences as $occurrence) {
            if ($occurrence === $headersVar) {
                continue;
            }

            $assign = $occurrence instanceof Variable ? $occurrence->getAttribute('parent') : null;

            if (!$occurrence instanceof Variable
                || !$assign instanceof Assign
                || $assign->var !== $occurrence
                || !$assign->expr instanceof Array_
                || $assign->getEndFilePos() >= $call->getStartFilePos()
            ) {
                return null;
            }

            $assignedArray = $assign->expr;
        }

        return $assignedArray;
    }

    /**
     * Finds the innermost function-like whose span contains `$call`. Top-level code (no enclosing
     * function-like) returns null, which keeps the sink: there is no scope to prove a single
     * assignment within.
     *
     * @param list<Node\Stmt> $stmts
     */
    private static function findEnclosingFunctionLike(array $stmts, MethodCall|StaticCall|New_ $call): ?FunctionLike
    {
        $callStart = $call->getStartFilePos();
        $callEnd = $call->getEndFilePos() + 1;

        /** @var list<FunctionLike> $candidates */
        $candidates = (new NodeFinder())->find($stmts, static fn(Node $node): bool => $node instanceof FunctionLike
            && $node->getStartFilePos() <= $callStart
            && $node->getEndFilePos() + 1 >= $callEnd);

        if ($candidates === []) {
            return null;
        }

        \usort($candidates, static fn(Node $a, Node $b): int => ($a->getEndFilePos() - $a->getStartFilePos()) <=> ($b->getEndFilePos() - $b->getStartFilePos()));

        return $candidates[0];
    }

    /**
     * `$$x`, `${$x}`, `extract()`, `compact()`, and `get_defined_vars()` read or write a variable
     * without ever producing a `Variable` node with the target name, so the occurrence count in
     * {@see resolveVariableHeaders()} cannot see them. Their mere presence anywhere in the scope
     * disqualifies every variable in it, since any of them could reach `$headersVar`.
     */
    private static function hasDynamicVariableAccess(FunctionLike $scope): bool
    {
        return (new NodeFinder())->findFirst($scope, static function (Node $node): bool {
            if ($node instanceof Variable) {
                return !\is_string($node->name);
            }

            return $node instanceof FuncCall
                && $node->name instanceof Name
                && !$node->name instanceof Relative
                && \in_array(\strtolower($node->name->toString()), ['extract', 'compact', 'get_defined_vars'], true);
        }) !== null;
    }

    /** @psalm-mutation-free */
    private static function provesAttachment(Array_ $headers): bool
    {
        $hasAttachment = false;

        foreach ($headers->items as $item) {
            if ($item === null
                || $item->unpack
                || !$item->key instanceof String_
                || !$item->value instanceof String_
            ) {
                return false;
            }

            if (\strtr($item->key->value, self::HEADER_NAME_UPPER, self::HEADER_NAME_LOWER) !== 'content-disposition') {
                continue;
            }

            // Two entries that fold to the same name are one header at runtime, and
            // `ResponseHeaderBag::set()` replaces, so the LAST one decides. Proof also needs the
            // canonical spelling: an entry that only reaches this name through Symfony's underscore
            // folding is exotic enough to keep the sink, as it did before that folding was modelled.
            if ($hasAttachment
                || \strtolower($item->key->value) !== 'content-disposition'
                || !self::isAttachmentDisposition($item->value->value)
            ) {
                return false;
            }

            $hasAttachment = true;
        }

        return $hasAttachment;
    }

    /** @psalm-pure */
    private static function isAttachmentDisposition(string $disposition): bool
    {
        // Checked on the RAW value, before trim(): trim() eats a boundary CR, LF, NUL or vertical
        // tab and would approve a value PHP still refuses to emit at runtime (the header is
        // dropped, the response renders as HTML), and `\s` in the parameter branch would accept an
        // inner one. Every C0 control and DEL is rejected, horizontal tab excepted: HTAB is the one
        // control a header field value may legitimately carry, as optional whitespace.
        if (\preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $disposition) === 1) {
            return false;
        }

        // The token has to end the value or be followed by its parameter list. Parameters
        // themselves are not validated: every browser downloads on an `attachment` token whatever
        // follows it, so rejecting an unrecognised parameter would only manufacture false
        // positives on shapes like `filename*=UTF-8''x.csv`.
        return \preg_match('/^attachment(?:\s*;\s*\S.*)?$/i', \trim($disposition)) === 1;
    }
}
