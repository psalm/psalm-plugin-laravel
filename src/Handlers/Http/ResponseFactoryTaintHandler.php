<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Http;

use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\Facades\Response;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use Psalm\CodeLocation;
use Psalm\Issue\TaintedHtml;
use Psalm\Plugin\EventHandler\BeforeAddIssueInterface;
use Psalm\Plugin\EventHandler\Event\BeforeAddIssueEvent;

/**
 * Suppresses the `TaintedHtml` finding on `ResponseFactory::make()` content when the call's literal
 * headers prove the browser downloads the response instead of rendering it.
 *
 * The exemption is applied when the issue is emitted, not by editing the taint graph. A graph
 * removal is keyed on an AST node, and Psalm dispatches the removal event for a call node a second
 * time while fetching that callee's own return type, which lands the removal on the callee's shared
 * project-wide `@psalm-flow` edge and silences unrelated flows (vimeo/psalm#11924). Nothing is
 * written here, so nothing can leak: the worst failure mode is a retained finding.
 *
 * Taint findings are emitted in the MAIN process after the worker pool exits, so no state recorded
 * by a per-expression hook survives to this point. Every fact is re-derived from the issue plus a
 * fresh look at the sink's file, and the handler holds no state at all.
 *
 * The proof stays deliberately syntactic: the headers argument must be an array literal at the call
 * site with every entry a literal string pair. A variable holding the very same attachment array
 * keeps the sink. Each such miss fails toward a retained finding, never a dropped one.
 */
final class ResponseFactoryTaintHandler implements BeforeAddIssueInterface
{
    /**
     * Journey-tail labels that mark the response factory's html content sink.
     *
     * Derived empirically on Psalm 7.0.0-beta19 by dumping `TaintedInput::$journey` tails for the
     * concrete, contract, facade, root-alias and `response()` helper call routes. Two properties of
     * that dump shape this list:
     *
     * - The tail is the ARGUMENT node feeding the sink, one node short of the sink itself, and its
     *   label is `call to <declaring class>::<method>`.
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
    ];

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

        return $call !== null && self::provesLiteralAttachment($call) ? false : null;
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
     * Locates the `make()` call whose content argument spans exactly `$bounds`.
     *
     * The receiver is deliberately not inspected: `parent::make()`, `$this->make()` and
     * `static::make()` all reach the stubbed sink, and only Psalm's own resolution — already proven
     * by the journey label — can tell them apart. More than one match is treated as unproven.
     *
     * @param list<Node\Stmt> $stmts
     * @param array{0: int, 1: int} $bounds
     */
    private static function findMakeCallWithContentAt(array $stmts, array $bounds): MethodCall|StaticCall|null
    {
        /** @psalm-var list<MethodCall|StaticCall> $calls */
        $calls = (new NodeFinder())->find($stmts, static function (Node $node) use ($bounds): bool {
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
     * Named arguments are rejected rather than resolved: in-order named arguments carry the same
     * content span as positional ones, so accepting the span alone would silently exempt them.
     */
    private static function provesLiteralAttachment(MethodCall|StaticCall $call): bool
    {
        $args = $call->getArgs();

        if (\count($args) !== 3
            || $args[0]->name !== null
            || $args[1]->name !== null
            || $args[2]->name !== null
            || $args[0]->unpack
            || $args[1]->unpack
            || $args[2]->unpack
            || !$args[2]->value instanceof Array_
        ) {
            return false;
        }

        return self::provesAttachment($args[2]->value);
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
}
