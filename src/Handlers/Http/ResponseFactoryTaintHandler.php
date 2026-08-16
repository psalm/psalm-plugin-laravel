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
 * Removes html taint from `ResponseFactory::make()` content only for literal attachment responses.
 *
 * `AddRemoveTaintsEvent` exposes neither call nor argument offset, so the preceding call hook
 * records only its exact content node. The file hook prevents AST object-id reuse across files.
 */
final class ResponseFactoryTaintHandler implements
    BeforeExpressionAnalysisInterface,
    RemoveTaintsInterface,
    BeforeFileAnalysisInterface
{
    /** @var array<int, Expr|null> */
    private static array $recordedContentIds = [];

    #[\Override]
    public static function beforeExpressionAnalysis(BeforeExpressionAnalysisEvent $event): ?bool
    {
        $call = $event->getExpr();

        if ((!$call instanceof MethodCall && !$call instanceof StaticCall)
            || !$event->getCodebase()->taint_flow_graph instanceof \Psalm\Internal\Codebase\TaintFlowGraph
            || !$call->name instanceof Identifier
            || \strtolower($call->name->name) !== 'make'
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

        if (!$receiver instanceof \PhpParser\Node\Expr) {
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

    /** @psalm-external-mutation-free */
    #[\Override]
    public static function beforeAnalyzeFile(BeforeFileAnalysisEvent $event): void
    {
        self::$recordedContentIds = [];
    }
}
