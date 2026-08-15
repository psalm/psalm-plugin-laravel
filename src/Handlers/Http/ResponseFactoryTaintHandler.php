<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Http;

use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\Facades\Response;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
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
 * Removes the html taint from `ResponseFactory::make()` content only when a literal headers
 * argument proves Symfony will not render the response as HTML (#1345).
 *
 * The `make()` stubs deliberately retain their html sinks: an omitted, dynamic, HTML, or unknown
 * header response defaults to HTML. `AddRemoveTaintsEvent` carries only the expression, not the
 * method id or argument offset, so this handler records the exact `$content` node during the
 * preceding whole-call hook and consumes only that node during taint removal. A global strip for
 * safe-looking literals would remove html taint from unrelated array and argument flows.
 *
 * `beforeAnalyzeFile()` clears the node-id set at file start. Psalm releases each file's AST after
 * analysis, allowing `spl_object_id()` reuse; clearing at the start also survives a previous file's
 * analysis exception. This is required until Psalm exposes the call method and argument offset on
 * `AddRemoveTaintsEvent`.
 */
final class ResponseFactoryTaintHandler implements
    BeforeExpressionAnalysisInterface,
    RemoveTaintsInterface,
    BeforeFileAnalysisInterface
{
    /**
     * MIME types whose browser handling does not parse the body as HTML. SVG is intentionally
     * excluded: it is image media that can contain active markup.
     *
     * @var array<string, true>
     */
    private const SAFE_CONTENT_TYPES = [
        'application/csv' => true,
        'application/icalendar' => true,
        'application/json' => true,
        'application/octet-stream' => true,
        'application/pdf' => true,
        'application/xml' => true,
        'image/avif' => true,
        'image/bmp' => true,
        'image/gif' => true,
        'image/jpeg' => true,
        'image/png' => true,
        'image/tiff' => true,
        'image/webp' => true,
        'text/calendar' => true,
        'text/csv' => true,
        'text/plain' => true,
        'text/xml' => true,
    ];

    /**
     * Exact content-node ids awaiting `removeTaints`. A method receiver is rechecked at removal
     * time, after Psalm has inferred it; `null` records an exact `Response` facade static call that
     * was checked while the whole call was available.
     *
     * @var array<int, Expr|null>
     */
    private static array $recordedContentIds = [];

    #[\Override]
    public static function beforeExpressionAnalysis(BeforeExpressionAnalysisEvent $event): ?bool
    {
        $call = $event->getExpr();

        if (!$call instanceof MethodCall && !$call instanceof StaticCall) {
            return null;
        }

        if (!$event->getCodebase()->taint_flow_graph instanceof \Psalm\Internal\Codebase\TaintFlowGraph
            || !$call->name instanceof Identifier
            || \strtolower($call->name->name) !== 'make'
            || $call->isFirstClassCallable()
        ) {
            return null;
        }

        $args = $call->getArgs();

        // Only a positional `$content, $status, $headers` call is modelled. Any named, unpacked,
        // or otherwise dynamic argument binding keeps the stub sink.
        if (!isset($args[0], $args[1], $args[2])
            || $args[0]->name !== null
            || $args[1]->name !== null
            || $args[2]->name !== null
            || $args[0]->unpack
            || $args[1]->unpack
            || $args[2]->unpack
            || !$args[2]->value instanceof Array_
            || !self::provesNonHtmlResponse($args[2]->value)
        ) {
            return null;
        }

        if ($call instanceof StaticCall) {
            if (!self::isExactResponseFacade($call)) {
                return null;
            }

            self::$recordedContentIds[\spl_object_id($args[0]->value)] = null;

            return null;
        }

        self::$recordedContentIds[\spl_object_id($args[0]->value)] = $call->var;

        return null;
    }

    #[\Override]
    public static function removeTaints(AddRemoveTaintsEvent $event): int
    {
        $content = $event->getExpr();

        if (!\array_key_exists(\spl_object_id($content), self::$recordedContentIds)) {
            return 0;
        }

        $receiver = self::$recordedContentIds[\spl_object_id($content)];

        if ($receiver === null) {
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

    /**
     * Simulates Symfony HeaderBag's case-insensitive, underscore-normalizing replacement semantics
     * in literal insertion order. A later literal `content-type` or `content-disposition` therefore
     * overrides an earlier safe declaration, while a dynamic key/value leaves the response unproven.
     *
     * @psalm-mutation-free
     */
    private static function provesNonHtmlResponse(Array_ $headers): bool
    {
        /** @var array<string, string> $resolvedHeaders */
        $resolvedHeaders = [];

        foreach ($headers->items as $item) {
            if ($item === null || $item->unpack) {
                return false;
            }

            if ($item->key === null) {
                continue;
            }

            if (!$item->key instanceof String_) {
                return false;
            }

            $header = \strtr($item->key->value, '_ABCDEFGHIJKLMNOPQRSTUVWXYZ', '-abcdefghijklmnopqrstuvwxyz');

            if ($header !== 'content-type' && $header !== 'content-disposition') {
                continue;
            }

            $value = self::literalHeaderValue($item->value);

            if ($value === null) {
                return false;
            }

            $resolvedHeaders[$header] = $value;
        }

        return isset($resolvedHeaders['content-type']) && self::isSafeContentType($resolvedHeaders['content-type'])
            || isset($resolvedHeaders['content-disposition']) && self::isAttachment($resolvedHeaders['content-disposition']);
    }

    /**
     * HeaderBag accepts a string or a list of strings. A one-value list is equally determinate;
     * multi-value, keyed, unpacked, and dynamic lists keep the response unproven.
     *
     * @psalm-mutation-free
     */
    private static function literalHeaderValue(Expr $value): ?string
    {
        if ($value instanceof String_) {
            return $value->value;
        }

        if (!$value instanceof Array_ || \count($value->items) !== 1) {
            return null;
        }

        $item = $value->items[0];

        if ($item === null || $item->key !== null || $item->unpack || !$item->value instanceof String_) {
            return null;
        }

        return $item->value->value;
    }

    /** @psalm-pure */
    private static function isSafeContentType(string $contentType): bool
    {
        $mime = \strtolower(\trim(\explode(';', $contentType, 2)[0]));

        return isset(self::SAFE_CONTENT_TYPES[$mime]);
    }

    /** @psalm-pure */
    private static function isAttachment(string $contentDisposition): bool
    {
        return \preg_match('/^attachment(?:\s*;|\s*$)/i', \trim($contentDisposition)) === 1;
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

    /**
     * An exact class or interface type is required. Subclasses, intersections, templates, and
     * unions can replace `make()` with application-defined behavior, so they keep the stub sink.
     *
     * @psalm-mutation-free
     */
    private static function isExactFactoryReceiver(Union $receiverType): bool
    {
        if (!$receiverType->isSingle()) {
            return false;
        }

        $atomic = $receiverType->getSingleAtomic();

        if (!$atomic instanceof TNamedObject) {
            return false;
        }

        $class = \strtolower($atomic->value);

        return $class === \strtolower(ResponseFactory::class)
            || $class === \strtolower(ResponseFactoryContract::class);
    }

    /**
     * Clear scoped node ids before every file. See the class docblock for why the boundary is a
     * file, rather than a function-like or end-of-file hook.
     *
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function beforeAnalyzeFile(BeforeFileAnalysisEvent $event): void
    {
        self::$recordedContentIds = [];
    }
}
