<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Taint;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\Event\AddRemoveTaintsEvent;
use Psalm\Plugin\EventHandler\RemoveTaintsInterface;
use Psalm\Type\Atomic\TLiteralInt;
use Psalm\Type\TaintKind;
use Psalm\Type\Union;

/**
 * Treats `json_encode()` with a literal `JSON_HEX_TAG` flag as html-escaping.
 *
 * Psalm core models `json_encode()` as a plain taint pass-through, so the Blade `@json` directive
 * (which compiles to `json_encode($x, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT, 512)`)
 * and hand-written equivalents report TaintedHtml on output that cannot contain `<` or `>`.
 * Core already does this for `htmlspecialchars()` flags (`HtmlFunctionTainter`); it has no `json_encode()` branch.
 *
 * Removal is per call site. The stripped edge is the `json_encode()` stub's own argument-to-return
 * edge, which Psalm specializes per call location for stubs it ships. A project that re-declares
 * `json_encode()` in its own stub loses that specialization and the strip would pool across sites.
 *
 * - HEX_TAG removes html only. The has_quotes taint is deliberately kept whatever the other HEX flags
 *   say: `json_encode()` always emits raw `"` delimiters around attacker-controlled content, so
 *   `<div data-x="@json($x)">` is still an attribute breakout (unlike `htmlspecialchars(ENT_QUOTES)`,
 *   whose output has no quotes at all). `Js::from()` is attribute-safe only because it wraps the
 *   payload in `JSON.parse('...')`.
 * - Flags are read from the literal node type, AND-ed across a literal union so only bits set in
 *   every possible value count. Any non-literal atomic, unpack argument, or missing flags declines.
 * - The `flags` named argument is honoured; the encoded value must stay positional, because
 *   {@see NamedArgumentTaintHandler} strips a named value before this handler matters.
 * - Only the core function qualifies: `\json_encode`, or an unqualified call whose resolved id is
 *   `json_encode` or a missing `<namespace>\json_encode` (Psalm's fallback to the global function).
 *   A userland function of that name, `\Foo\json_encode()`, and an alias to any other function decline.
 *   `use function json_encode as enc` is a known false positive of the written-name gate: the
 *   finding is kept.
 *
 * Return-statement leak: `ReturnAnalyzer` accumulates the removal it gets for a returned expression
 * into the enclosing function's storage, which then applies to EVERY return path of that function.
 * Returning a stripped call would silently strip a sibling `return $tainted;` (order dependent).
 * The handler therefore acts only while the call's type is still unset, which is the dispatch from
 * `FunctionCallReturnTypeFetcher` that writes the edge. Return and argument dispatches see the type
 * already set and decline. This is an internal-ordering heuristic, not a contract. If a future
 * Psalm sets the type earlier, the handler strips nothing (false positives return, the Safe phpts
 * go red) and never over-strips.
 *
 * Retirement: delete once core adds a `json_encode()` branch to `HtmlFunctionTainter` (or a sibling).
 */
final class JsonEncodeTaintHandler implements RemoveTaintsInterface
{
    #[\Override]
    public static function removeTaints(AddRemoveTaintsEvent $event): int
    {
        // Runs for nearly every expression node, so bail on the cheapest checks first.
        $expr = $event->getExpr();
        if (!$expr instanceof FuncCall
            || !$expr->name instanceof Name
            || \strtolower($expr->name->getLast()) !== 'json_encode'
            || $expr->isFirstClassCallable()
        ) {
            return 0;
        }

        $source = $event->getStatementsSource();
        if (!$source instanceof StatementsAnalyzer || $source->node_data->getType($expr) instanceof Union) {
            return 0;
        }

        if (!self::resolvesToCoreFunction($event, $expr->name, $source)) {
            return 0;
        }

        $flagsArg = null;
        foreach ($expr->getArgs() as $position => $arg) {
            if ($arg->unpack) {
                return 0;
            }

            if ($arg->name !== null ? $arg->name->name === 'flags' : $position === 1) {
                $flagsArg = $arg;
            }
        }

        if (!$flagsArg instanceof Arg) {
            return 0;
        }

        $flags = self::provenFlagBits($source, $flagsArg);
        if ($flags === null) {
            return 0;
        }

        return ($flags & \JSON_HEX_TAG) !== 0 ? TaintKind::INPUT_HTML : 0;
    }

    /** Mirrors FunctionCallAnalyzer's function-id resolution: fully qualified, or unqualified with a namespace fallback. */
    private static function resolvesToCoreFunction(AddRemoveTaintsEvent $event, Name $name, StatementsAnalyzer $source): bool
    {
        if ($name instanceof Name\FullyQualified) {
            return \strtolower($name->toString()) === 'json_encode';
        }

        if (\count($name->getParts()) !== 1) {
            return false;
        }

        $functions = $event->getCodebase()->functions;
        $functionId = \strtolower($functions->getFullyQualifiedFunctionNameFromString($name->toString(), $source));

        if ($functionId === 'json_encode') {
            return true;
        }

        $namespace = $source->getNamespace();

        return $namespace !== null && $namespace !== ''
            && $functionId === \strtolower($namespace) . '\\json_encode'
            && !$functions->functionExists($source, $functionId);
    }

    /** Bits set in every possible literal value of the flags argument, or null when any value is unknown. */
    private static function provenFlagBits(StatementsAnalyzer $source, Arg $flagsArg): ?int
    {
        $type = $source->node_data->getType($flagsArg->value);
        if (!$type instanceof Union) {
            return null;
        }

        $flags = -1;
        foreach ($type->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TLiteralInt) {
                return null;
            }

            $flags &= $atomic->value;
        }

        return $flags;
    }
}
