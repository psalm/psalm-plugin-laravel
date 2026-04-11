<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Collections;

use Illuminate\Support\HigherOrderCollectionProxy;
use PhpParser\Node\Expr\MethodCall;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Union;

/**
 * Fixes a false-positive InvalidMethodCall when chaining collection methods after a
 * higher-order proxy call, e.g.:
 *
 *   $collection->sortByDesc->getTotalCompletedTimeInHours()->values()
 *
 * Root cause: HigherOrderCollectionProxy has @mixin TValue in Laravel source.
 * Psalm's @mixin TValue makes it resolve getTotalCompletedTimeInHours() through
 * the item type (TValue), returning int. The chained ->values() on int triggers
 * InvalidMethodCall.
 *
 * Psalm's stub system merges (not replaces) class-level @mixin annotations, so a
 * stub removing @mixin TValue does not fix the issue. The MethodReturnTypeProvider
 * hook also does not fire for mixin-resolved methods — by the time MethodCallReturnTypeFetcher
 * runs, the $premixin_method_id already reflects the mixin-resolved class (TValue).
 *
 * This handler uses AfterMethodCallAnalysisInterface, which fires after any method
 * call analysis. We detect calls on a HigherOrderCollectionProxy by checking the
 * static type of the callee expression ($expr->var), then override the return type
 * to Enumerable<TKey, TValue> — correctly modelling the collection-level result of
 * proxy methods like sortByDesc, map, filter, etc.
 *
 * Trade-off: aggregate proxies (sum, avg, max) are also typed as Enumerable instead
 * of scalars. This is acceptable because the false positive is a hard error, whereas
 * the loss of precision for aggregate proxies is minor and rarely cascades.
 */
final class HigherOrderCollectionProxyHandler implements AfterMethodCallAnalysisInterface
{
    // Pre-lowercased constant avoids repeated strtolower() in the inner loop,
    // which runs for every method call in the analyzed codebase.
    private const PROXY_CLASS_LOWER = 'illuminate\support\higherordercollectionproxy';

    #[\Override]
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        $expr = $event->getExpr();

        // Only handle instance method calls, not static calls.
        if (!$expr instanceof MethodCall) {
            return;
        }

        $source = $event->getStatementsSource();
        $calleeType = $source->getNodeTypeProvider()->getType($expr->var);

        if (!$calleeType instanceof \Psalm\Type\Union) {
            return;
        }

        foreach ($calleeType->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TGenericObject
                || \strtolower($atomic->value) !== self::PROXY_CLASS_LOWER
            ) {
                continue;
            }

            $typeParams = $atomic->type_params;
            if (\count($typeParams) < 2) {
                return;
            }

            // Override the @mixin TValue resolution (e.g. int) with the collection-level result,
            // enabling correct chaining of collection methods after proxy calls.
            $event->setReturnTypeCandidate(new Union([
                new TGenericObject('Illuminate\Support\Enumerable', [$typeParams[0], $typeParams[1]]),
            ]));

            return;
        }
    }
}
