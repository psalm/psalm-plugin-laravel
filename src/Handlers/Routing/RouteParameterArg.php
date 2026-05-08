<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Routing;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Type\Union;

/**
 * Shared first-argument literal extractor for `Request::route('name')` calls.
 *
 * The two routing handlers (return type provider, taint handler) both need
 * to read the literal parameter name out of a `route(...)` call, but Psalm
 * exposes the call shape differently in each event:
 *
 *  - {@see MethodReturnTypeProviderEvent::getCallArgs()} hands us a
 *    pre-extracted `list<Arg>`.
 *  - {@see \Psalm\Plugin\EventHandler\Event\AddRemoveTaintsEvent::getExpr()}
 *    hands us a raw {@see MethodCall} AST node.
 *
 * Extracting the same logic into one helper keeps the two handlers from
 * drifting apart on edge cases (no args, dynamic first arg, default arg,
 * non-literal expression).
 *
 * @internal
 */
final class RouteParameterArg
{
    /**
     * @param list<Arg> $callArgs
     */
    public static function extractLiteralName(
        array $callArgs,
        StatementsAnalyzer $statementsAnalyzer,
    ): ?string {
        if ($callArgs === []) {
            // route() with no arg returns the Route object — handled by stub.
            return null;
        }

        $type = $statementsAnalyzer->node_data->getType($callArgs[0]->value);

        if (!$type instanceof Union || !$type->isSingleStringLiteral()) {
            return null;
        }

        return $type->getSingleStringLiteral()->value;
    }

    public static function extractLiteralNameFromCall(
        MethodCall $call,
        StatementsAnalyzer $statementsAnalyzer,
    ): ?string {
        return self::extractLiteralName(\array_values($call->getArgs()), $statementsAnalyzer);
    }
}
