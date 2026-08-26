<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Http;

use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\Facades\Response;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\BeforeExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\BeforeFileAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AddRemoveTaintsEvent;
use Psalm\Plugin\EventHandler\Event\BeforeExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\BeforeFileAnalysisEvent;
use Psalm\Plugin\EventHandler\RemoveTaintsInterface;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\TaintKind;
use Psalm\Type\Union;

/**
 * Removes html taint from `ResponseFactory::make()` content only for literal attachment responses.
 *
 * `AddRemoveTaintsEvent` exposes neither call nor argument offset, so the preceding call hook
 * records only its exact content node, keyed by object identity.
 *
 * The exemption is deliberately syntactic: the headers argument must be an array literal at the
 * call site with every entry a literal string pair, and content produced directly by a function
 * or static call is never exempted. A variable holding the very same attachment array keeps the
 * sink. Each of those misses fails toward a retained finding, never a dropped one.
 */
final class ResponseFactoryTaintHandler implements
    BeforeExpressionAnalysisInterface,
    RemoveTaintsInterface,
    BeforeFileAnalysisInterface
{
    /**
     * Content nodes exempted from the html sink, mapped to the receiver node whose type
     * {@see removeTaints} still has to resolve, or `null` for a `StaticCall` already verified at
     * record time by {@see isExactResponseFacade}, which skips that check.
     *
     * Keyed WEAKLY, on the node object rather than its `spl_object_id`. Psalm frees whole ASTs
     * other than the analysed file's while that file is still being analysed —
     * `ProjectAnalyzer::getMethodMutations()` parses, analyses and releases a foreign file on the
     * constructor-initialisation path, and `ClassLikes::getTraitNode()` keeps only the `Trait_` node
     * of the file it parsed — and neither dispatches `BeforeFileAnalysisEvent`. Those bodies do reach
     * {@see beforeExpressionAnalysis}, so an id-keyed record can outlive its node, and PHP then hands
     * the freed handle to an unrelated fresh node. A stale hit STRIPS taint, so that collision is a
     * missed vulnerability, not a false positive. A weak key cannot survive its node.
     *
     * The receiver is wrapped in a shape rather than stored bare, because `WeakMap::offsetExists()`
     * is `isset`-shaped: a bare `null` value reads back as absent, which would silently disable the
     * facade path.
     *
     * @psalm-var \WeakMap<object, array{receiver: Expr|null}>|null
     */
    private static ?\WeakMap $recordedContent = null;

    /**
     * `new \WeakMap()` alone infers `WeakMap<object, mixed>`, so the value shape is pinned here
     * rather than at each of the two construction sites.
     *
     * @return \WeakMap<object, array{receiver: Expr|null}>
     *
     * @psalm-pure
     */
    private static function newRecordMap(): \WeakMap
    {
        /** @psalm-var \WeakMap<object, array{receiver: Expr|null}> $map */
        $map = new \WeakMap();

        return $map;
    }

    #[\Override]
    public static function beforeExpressionAnalysis(BeforeExpressionAnalysisEvent $event): ?bool
    {
        $call = $event->getExpr();

        if ((!$call instanceof MethodCall && !$call instanceof StaticCall)
            || !$event->getCodebase()->taint_flow_graph instanceof \Psalm\Internal\Codebase\TaintFlowGraph
            || !$call->name instanceof Identifier
            || \strtolower($call->name->name) !== 'make'
            // getArgs() throws on a first-class callable (`make(...)`).
            || $call->isFirstClassCallable()
        ) {
            return null;
        }

        $args = $call->getArgs();

        if (\count($args) !== 3
            || $args[0]->name !== null
            || $args[1]->name !== null
            || $args[2]->name !== null
            || $args[0]->unpack
            || $args[1]->unpack
            || $args[2]->unpack
            || !$args[2]->value instanceof Array_
            || !self::provesAttachment($args[2]->value)
        ) {
            return null;
        }

        // Content produced directly by a function or static call is never recorded: Psalm
        // dispatches `AddRemoveTaintsEvent` for that same node a second time while fetching the
        // callee's own return type, and for a flow-carrying callee (`@psalm-flow` without
        // `@psalm-taint-specialize`, e.g. `decrypt()`) the removal is written onto the callee's
        // single project-wide argument-to-return edge, silencing html findings on every unrelated
        // flow through it (the #1395 failure class). `make(decrypt(...), ...)` therefore keeps a
        // false positive. A method call is safe to record: Psalm dispatches its removal event on
        // the receiver node, not the call node.
        if ($args[0]->value instanceof FuncCall || $args[0]->value instanceof StaticCall) {
            return null;
        }

        if ($call instanceof StaticCall) {
            if (!self::isExactResponseFacade($call)) {
                return null;
            }

            (self::$recordedContent ??= self::newRecordMap())
                ->offsetSet($args[0]->value, ['receiver' => null]);

            return null;
        }

        (self::$recordedContent ??= self::newRecordMap())
            ->offsetSet($args[0]->value, ['receiver' => $call->var]);

        return null;
    }

    #[\Override]
    public static function removeTaints(AddRemoveTaintsEvent $event): int
    {
        $content = $event->getExpr();
        $recorded = self::$recordedContent;

        if (!$recorded instanceof \WeakMap || !$recorded->offsetExists($content)) {
            return 0;
        }

        $entry = $recorded->offsetGet($content);

        if ($entry === null) {
            return 0;
        }

        $receiver = $entry['receiver'];

        if (!$receiver instanceof Expr) {
            return TaintKind::INPUT_HTML;
        }

        $statementsSource = $event->getStatementsSource();

        if (!$statementsSource instanceof StatementsAnalyzer) {
            return 0;
        }

        $receiverType = $statementsSource->node_data->getType($receiver);

        return $receiverType instanceof Union && self::isExactFactoryReceiver($receiverType)
            ? TaintKind::INPUT_HTML
            : 0;
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

            $header = \strtolower($item->key->value);

            if ($header !== 'content-disposition') {
                continue;
            }

            if ($hasAttachment || !self::isAttachmentDisposition($item->value->value)) {
                return false;
            }

            $hasAttachment = true;
        }

        return $hasAttachment;
    }

    /** @psalm-pure */
    private static function isAttachmentDisposition(string $disposition): bool
    {
        // Checked on the RAW value, before trim(): trim() eats a boundary CR/LF/NUL and would
        // approve a value PHP still refuses to emit at runtime (the header is dropped, the
        // response renders as HTML), and `\s` in the parameter branch would accept an inner one.
        if (\strpbrk($disposition, "\r\n\0") !== false) {
            return false;
        }

        return \preg_match('/^attachment(?:\s*;\s*\S.*)?$/i', \trim($disposition)) === 1;
    }

    private static function isExactResponseFacade(StaticCall $call): bool
    {
        if (!$call->class instanceof Name || $call->class->isSpecialClassName()) {
            return false;
        }

        /** @psalm-var ?string $resolved */
        $resolved = $call->class->getAttribute('resolvedName');
        $class = \is_string($resolved) ? $resolved : $call->class->toString();

        return \strtolower($class) === \strtolower(Response::class);
    }

    /** @psalm-mutation-free */
    private static function isExactFactoryReceiver(Union $receiverType): bool
    {
        if (!$receiverType->isSingle()) {
            return false;
        }

        $atomic = $receiverType->getSingleAtomic();

        if (!$atomic instanceof TNamedObject || $atomic->extra_types !== []) {
            return false;
        }

        $class = \strtolower($atomic->value);

        return $class === \strtolower(ResponseFactory::class)
            || $class === \strtolower(ResponseFactoryContract::class);
    }

    /**
     * Drop the recorded nodes at file START. Correctness no longer rests on this — the weak keys
     * bound each entry to its own node's lifetime — but it caps the footprint at one file's exempted
     * calls and, unlike an end-of-file flush, survives a mid-file analysis throw.
     *
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function beforeAnalyzeFile(BeforeFileAnalysisEvent $event): void
    {
        self::$recordedContent = self::newRecordMap();
    }
}
