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
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use Psalm\CodeLocation;
use Psalm\Issue\TaintedHtml;
use Psalm\Plugin\EventHandler\BeforeAddIssueInterface;
use Psalm\Plugin\EventHandler\Event\BeforeAddIssueEvent;

/**
 * Suppresses the `TaintedHtml` finding on a `ResponseFactory::make()` or `Illuminate\Http\Response`
 * constructor call when the call's headers prove the browser downloads the response, or that the
 * declared content type is never sniffed as HTML, instead of rendering it.
 *
 * The exemption is applied when the issue is emitted and the taint graph is never edited, so no
 * decision made here can reach another flow. Nothing is carried over from the analysis phase
 * either: every fact is re-derived from the issue plus a fresh look at the sink's file. Rationale
 * for both, and the dead end they replace, in `docs/contributing/decisions.md` under "Call-site
 * sink exemptions are applied at issue emission" (vimeo/psalm#11924).
 *
 * The proof stays deliberately syntactic, never control-flow aware. The headers argument must
 * either be an array literal at the call site, or a local variable proven to hold exactly one
 * array literal: assigned once as a direct top-level statement of the enclosing function-like's
 * own body (never nested in a ternary, `if`, loop, or `try`, which could let one runtime branch
 * feed the sink an array a sibling branch proved safe), before the call, never reassigned,
 * mutated, passed elsewhere, or captured by a nested closure, and the scope must not touch a
 * variable-variable, a dynamic function-name callee, or `extract()`/`compact()`/
 * `get_defined_vars()` under any alias Psalm's own name resolution can see through. An
 * `ArrowFunction` scope never resolves anything, since its body has no statement list for a
 * "direct top-level statement" to belong to. Superglobals and `$this` are rejected outright. Each
 * such miss fails toward a retained finding, never a dropped one.
 *
 * ACCEPTED LIMITATION: every `make()` call in a project shares one sink node (and, separately,
 * every constructor call shares its own), and `TaintFlowGraph::getChildNodes()` walks each once,
 * so its `visited_source_ids` set already discards every flow into it longer than the first one
 * found. Exempting that first flow leaves the sink reporting nothing rather than reporting one of
 * its flows. The longer flow is lost with or without this handler; pinned for `make()` by
 * `TaintedHtmlResponseFactoryMakeSharedSinkKnownLimitation.phpt`.
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

    /**
     * Every C0 control and DEL, horizontal tab excepted: HTAB is the one control a header field
     * value may legitimately carry, as optional whitespace. Checked on the RAW value, before
     * trim(): trim() eats a boundary CR, LF, NUL, or vertical tab and would approve a value PHP
     * still refuses to emit at runtime (the header is dropped, the response renders as HTML).
     */
    private const RAW_CONTROL_CHARACTER_PATTERN = '/[\x00-\x08\x0A-\x1F\x7F]/';

    /**
     * PHP's superglobals are implicitly available, and implicitly mutable, in every scope without
     * ever producing a matching `Variable` node for a competing write, and `$this` is bound by the
     * runtime rather than by an assignment this proof could ever find. Const-folding any of these
     * would be unsound regardless of how the occurrence count comes out.
     */
    private const NEVER_RESOLVED_VARIABLE_NAMES = [
        'GLOBALS', '_GET', '_POST', '_COOKIE', '_REQUEST', '_SERVER', '_ENV', '_FILES', '_SESSION', 'this',
    ];

    /**
     * Per the WHATWG MIME Sniffing spec (https://mimesniff.spec.whatwg.org/), a supplied
     * Content-Type is returned unchanged for every essence except the narrow exception set in
     * {@see UNSAFE_MEDIA_TYPE_ESSENCES}. Outside that set, `html` and `xml` cover `text/html`,
     * `application/xhtml+xml`, every `*+xml` suffix (XML renders an XHTML-namespaced `<script>`),
     * and `image/svg+xml`. `script` covers the WHATWG JavaScript MIME group in one needle -
     * `text/javascript`, `application/javascript`, `text/jscript`, `text/ecmascript`,
     * `application/x-ecmascript`, `text/livescript`, and every other member contains the substring
     * `script`, and a response an attacker controls is exactly as dangerous loaded as an external
     * `<script>` as it is rendered as HTML. Known collateral, an accepted retained finding rather
     * than a dropped one: `application/postscript` also contains `script` and keeps the sink even
     * though it is not a script MIME type. `multipart/*` is excluded because its parts are not the
     * declared type at all. Everything else well-formed, vendor download types included, is exempt.
     */
    private const UNSAFE_MEDIA_TYPE_NEEDLES = ['html', 'xml', 'script'];

    /**
     * The essences the WHATWG MIME Sniffing spec's "rules for identifying an unknown MIME type"
     * fall through to sniffing for, whose result CAN be `text/html`: an undefined supplied type,
     * or one whose essence is `unknown/unknown`, `application/unknown`, or the wildcard-over-
     * wildcard essence (a bare `*` on both sides of the slash). The wildcard essence never reaches
     * this list: the token regex in {@see isSafeContentType()} already rejects a bare `*`.
     */
    private const UNSAFE_MEDIA_TYPE_ESSENCES = ['unknown/unknown', 'application/unknown'];

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

        $call = self::findResponseCallWithContentAt($stmts, $content->getSelectionBounds());

        return $call !== null && self::provesExemptResponse($stmts, $call) ? false : null;
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
    private static function findResponseCallWithContentAt(array $stmts, array $bounds): MethodCall|StaticCall|New_|null
    {
        /** @psalm-var list<MethodCall|StaticCall|New_> $calls */
        $calls = (new NodeFinder())->find($stmts, static function (Node $node) use ($bounds): bool {
            if (!$node instanceof New_ && !$node instanceof MethodCall && !$node instanceof StaticCall) {
                return false;
            }

            if (!$node instanceof New_
                && (!$node->name instanceof Identifier || \strtolower($node->name->name) !== 'make')
            ) {
                return false;
            }

            // getArgs() throws on a first-class callable (`make(...)`, `new A(...)`).
            if ($node->isFirstClassCallable()) {
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
    private static function provesExemptResponse(array $stmts, MethodCall|StaticCall|New_ $call): bool
    {
        $args = $call->getArgs();

        if (\count($args) !== 3 || $args[2]->name !== null || $args[2]->unpack) {
            return false;
        }

        $headers = $args[2]->value;

        if ($headers instanceof Array_) {
            return self::provesExempt($headers);
        }

        if (!$headers instanceof Variable || !\is_string($headers->name)) {
            return false;
        }

        $resolved = self::resolveHeadersArray($stmts, $call, $headers, $headers->name);

        return $resolved instanceof Array_ && self::provesExempt($resolved);
    }

    /**
     * Resolves `$headersVar` to the single array literal assigned to it, when the enclosing
     * function-like proves that assignment DOMINATES the call: it is a direct top-level statement
     * of the function-like's own body (never nested in a ternary, `if`, loop, or `try`), it is the
     * only write and the call's argument the only other read, and it textually precedes the call.
     *
     * Deliberately syntactic, not control-flow aware otherwise: a straight-line assignment earlier
     * in the same body counts even where a real CFG would need to prove reachability, exactly like
     * a reassignment or a read anywhere else disqualifies it regardless of reachability. But an
     * assignment nested in a conditional branch is REJECTED even when it is textually the only one,
     * because the branch that executes at runtime need not be the one containing it (`$cond ? (
     * $headers = $safe) : $sink` runs one arm or the other, never both, and the AST shape alone
     * cannot tell which — see #1416 review). Requiring the direct-statement shape also means an
     * `ArrowFunction` scope never resolves anything: its body is a bare expression with no
     * statement list at all, so no assignment inside it can ever be a "direct top-level statement",
     * and its implicit-by-value variable capture would otherwise let an outer `$headers` leak in
     * unproven. Superglobals and `$this` are rejected outright: they are available in every scope
     * without ever producing a matching `Variable` node for a competing write, so the occurrence
     * count could never see a mutation this proof needs to rule out.
     *
     * `$name` is `$headersVar->name`, passed separately because the only caller already proved it
     * is a `string` (a computed variable name is a different `Variable` shape entirely, rejected
     * before this is ever called).
     *
     * @param list<Node\Stmt> $stmts
     */
    private static function resolveHeadersArray(array $stmts, MethodCall|StaticCall|New_ $call, Variable $headersVar, string $name): ?Array_
    {
        if (\in_array($name, self::NEVER_RESOLVED_VARIABLE_NAMES, true)) {
            return null;
        }

        // Parent pointers are the only way to climb from the call to its enclosing function-like,
        // and later to tell "the assignment's LHS" from any other read of the same name, without
        // re-implementing a scope walk; ParentConnectingVisitor mutates node attributes, but
        // re-running it is idempotent, so this stays safe to call once per issue.
        (new NodeTraverser(new ParentConnectingVisitor()))->traverse($stmts);

        /** @var Node|null $scope */
        $scope = $call->getAttribute('parent');

        while ($scope !== null && !$scope instanceof FunctionLike) {
            /** @var Node|null $scope */
            $scope = $scope->getAttribute('parent');
        }

        if (!$scope instanceof FunctionLike) {
            return null;
        }

        /** @var list<Variable|ClosureUse> $occurrences */
        $occurrences = (new NodeFinder())->find($scope, static fn(Node $node): bool => ($node instanceof Variable && \is_string($node->name) && $node->name === $name)
            || ($node instanceof ClosureUse && $node->var->name === $name));

        if (\count($occurrences) !== 2 || self::hasDynamicVariableAccess($scope)) {
            return null;
        }

        $assignedArray = null;

        foreach ($occurrences as $occurrence) {
            if ($occurrence === $headersVar) {
                continue;
            }

            $assign = $occurrence instanceof Variable ? $occurrence->getAttribute('parent') : null;
            $statement = $assign instanceof Assign ? $assign->getAttribute('parent') : null;

            // $statement must be the Assign's immediate Stmt\Expression wrapper (rejects a ternary,
            // and an ArrowFunction body, where the Assign's parent is never a statement at all),
            // AND that statement's own parent must be $scope itself (rejects an `if`/loop/`try`
            // body, whose statements are parented to the control-structure node instead).
            if (!$occurrence instanceof Variable
                || !$assign instanceof Assign
                || $assign->var !== $occurrence
                || !$assign->expr instanceof Array_
                || !$statement instanceof Expression
                || $statement->getAttribute('parent') !== $scope
                || $statement->getEndFilePos() >= $call->getStartFilePos()
            ) {
                return null;
            }

            $assignedArray = $assign->expr;
        }

        return $assignedArray;
    }

    /**
     * `$$x`, `${$x}`, `extract()`, `compact()`, and `get_defined_vars()` read or write a variable
     * without ever producing a `Variable` node with the target name, so the occurrence count in
     * {@see resolveHeadersArray()} cannot see them. Their mere presence anywhere in the scope
     * disqualifies every variable in it, since any of them could reach `$headersVar`.
     *
     * A `FuncCall` with a non-`Name` callee (`$fn(...)`, `(fn())(...)`, ...) disqualifies
     * unconditionally: no static name comparison is possible, so treating it as "not one of the
     * three" would be a guess, not a proof. For a `Name` callee, every candidate id Psalm's own
     * name resolution can produce is checked against the last namespace segment,
     * case-insensitively, mirroring {@see \Psalm\LaravelPlugin\Handlers\Taint\
     * NamedArgumentTaintHandler::functionNameCandidates()}: `resolvedName` (set on the node while
     * the file's real analysis pass runs) first, then `namespacedName` (the in-namespace candidate
     * for an unqualified, unaliased call inside a namespace, where `resolvedName` is left
     * ambiguous), then the written spelling. So `use function extract as hydrate; hydrate($vars)`
     * is caught the same as a direct `extract()` call — the written name is `hydrate`, the
     * resolved one is `extract` — and so is a fully-qualified `\extract(...)`.
     */
    private static function hasDynamicVariableAccess(FunctionLike $scope): bool
    {
        return (new NodeFinder())->findFirst($scope, static function (Node $node): bool {
            if ($node instanceof Variable) {
                return !\is_string($node->name);
            }

            if (!$node instanceof FuncCall) {
                return false;
            }

            if (!$node->name instanceof Name) {
                return true;
            }

            foreach (['resolvedName', 'namespacedName'] as $attribute) {
                /** @var string|null $candidate */
                $candidate = $node->name->getAttribute($attribute);

                if (\is_string($candidate) && self::isExtractLikeName($candidate)) {
                    return true;
                }
            }

            return self::isExtractLikeName($node->name->toString());
        }) instanceof \PhpParser\Node;
    }

    /** @psalm-pure */
    private static function isExtractLikeName(string $calleeName): bool
    {
        $segments = \explode('\\', $calleeName);

        return \in_array(\strtolower($segments[\count($segments) - 1]), ['extract', 'compact', 'get_defined_vars'], true);
    }

    /**
     * A literal `Content-Disposition: attachment` entry proves the browser downloads the response
     * regardless of content type; a literal `Content-Type` naming a media type never sniffed as
     * HTML proves the opposite direction. Either alone exempts the call, checked in one pass so a
     * duplicate of one header cannot be masked by an unrelated proof on the other.
     *
     * Every entry needs a literal string key so a duplicate fold can always be detected; an entry
     * that is not itself proving disposition or content type may carry any value, since it cannot
     * retract a proof made by a different entry.
     *
     * @psalm-mutation-free
     */
    private static function provesExempt(Array_ $headers): bool
    {
        $dispositionSeen = false;
        $dispositionProven = false;
        $contentTypeSeen = false;
        $contentTypeProven = false;

        foreach ($headers->items as $item) {
            if ($item === null
                || $item->unpack
                || !$item->key instanceof String_
            ) {
                return false;
            }

            $folded = \strtr($item->key->value, self::HEADER_NAME_UPPER, self::HEADER_NAME_LOWER);

            if ($folded === 'content-disposition') {
                // Two entries that fold to the same name are one header at runtime, and
                // `ResponseHeaderBag::set()` replaces, so the LAST one decides. A second fold
                // invalidates the proof outright rather than re-checking the newer value: exotic
                // enough to keep the sink, as it did before folding was modelled.
                $dispositionProven = !$dispositionSeen
                    && \strtolower($item->key->value) === $folded
                    && self::provesAttachmentValue($item->value);
                $dispositionSeen = true;
            } elseif ($folded === 'content-type') {
                $contentTypeProven = !$contentTypeSeen
                    && \strtolower($item->key->value) === $folded
                    && $item->value instanceof String_
                    && self::isSafeContentType($item->value->value);
                $contentTypeSeen = true;
            }
        }

        return $dispositionProven || $contentTypeProven;
    }

    /**
     * True of the header GRAMMAR: nothing that can follow a literal `attachment;` parameter
     * separator can retract the token itself. ACCEPTED gap at runtime: a CR/LF arriving through
     * the unproven interpolated/concatenated suffix still drops the whole header the way
     * isAttachmentDisposition()'s docblock describes for an all-literal value, and Symfony's
     * Response::prepare() then defaults Content-Type to text/html, rendering the tainted body.
     * The control-character guard below only covers the literal prefix.
     *
     * @psalm-mutation-free
     */
    private static function provesAttachmentValue(Node\Expr $value): bool
    {
        if ($value instanceof String_) {
            return self::isAttachmentDisposition($value->value);
        }

        // Only the leading literal is trusted; the interpolated or concatenated suffix is never
        // inspected. literalPrefix() already returns null for anything that isn't
        // InterpolatedString, Concat, or (recursively) a String_ leaf, so no separate type gate
        // is needed to reject $value shapes it cannot handle.
        $prefix = self::literalPrefix($value);

        return $prefix !== null
            && \preg_match(self::RAW_CONTROL_CHARACTER_PATTERN, $prefix) !== 1
            && \preg_match('/^attachment\s*;/i', $prefix) === 1;
    }

    /**
     * The first interpolated string part, or the leftmost leaf of a left-associative `Concat`
     * chain. A multi-literal prefix split across several concatenated strings (`"a" . "b" . $x`) is
     * deliberately not reassembled: only the single leftmost leaf has to carry the whole
     * `attachment;` token.
     *
     * @psalm-mutation-free
     */
    private static function literalPrefix(Node\Expr $value): ?string
    {
        if ($value instanceof InterpolatedString) {
            $first = $value->parts[0] ?? null;

            if (!$first instanceof InterpolatedStringPart) {
                return null;
            }

            return $first->value;
        }

        if ($value instanceof Concat) {
            return self::literalPrefix($value->left);
        }

        if ($value instanceof String_) {
            return $value->value;
        }

        return null;
    }

    /** @psalm-pure */
    private static function isAttachmentDisposition(string $disposition): bool
    {
        // See RAW_CONTROL_CHARACTER_PATTERN's docblock for why this runs before trim(); `\s` in
        // the parameter branch below would otherwise accept an inner control character too.
        if (\preg_match(self::RAW_CONTROL_CHARACTER_PATTERN, $disposition) === 1) {
            return false;
        }

        // The token has to end the value or be followed by its parameter list. Parameters
        // themselves are not validated: every browser downloads on an `attachment` token whatever
        // follows it, so rejecting an unrecognised parameter would only manufacture false
        // positives on shapes like `filename*=UTF-8''x.csv`.
        return \preg_match('/^attachment(?:\s*;\s*\S.*)?$/i', \trim($disposition)) === 1;
    }

    /**
     * `$value` is the raw, un-trimmed header value: the same RAW_CONTROL_CHARACTER_PATTERN guard
     * as the disposition check runs first, because a runtime that drops the header for those
     * bytes serves the response as HTML regardless of what type was declared. Parameters after
     * the first `;` (`charset=`, boundary, ...) are not part of the media type and are discarded
     * before matching.
     *
     * @psalm-pure
     */
    private static function isSafeContentType(string $value): bool
    {
        if (\preg_match(self::RAW_CONTROL_CHARACTER_PATTERN, $value) === 1) {
            return false;
        }

        $mediaType = \trim(\explode(';', \strtolower($value), 2)[0]);

        if (\preg_match('~^[0-9a-z!#$&^_.+-]+/[0-9a-z!#$&^_.+-]+$~', $mediaType) !== 1) {
            return false;
        }

        // The exact essences the sniffing spec can still turn into text/html; see the const
        // docblock. Case-folded and parameter-stripped above, so `Application/Unknown;
        // charset=x` is caught here too.
        if (\in_array($mediaType, self::UNSAFE_MEDIA_TYPE_ESSENCES, true)) {
            return false;
        }

        if (\str_starts_with($mediaType, 'multipart/')) {
            return false;
        }

        foreach (self::UNSAFE_MEDIA_TYPE_NEEDLES as $needle) {
            if (\str_contains($mediaType, $needle)) {
                return false;
            }
        }

        return true;
    }
}
