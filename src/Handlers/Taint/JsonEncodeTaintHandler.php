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
 * Treats `json_encode()` with literal `JSON_HEX_*` flags as escaping, the way `Js::from()` is.
 *
 * Psalm core models `json_encode()` as a plain taint pass-through, so the Blade `@json` directive
 * (which compiles to `json_encode($x, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT, 512)`)
 * and hand-written equivalents report TaintedHtml and TaintedTextWithQuotes on safe output.
 * Core already does this for `htmlspecialchars()` flags (`HtmlFunctionTainter`); it has no `json_encode()` branch.
 *
 * Removal is per call site. The stripped edge is the `json_encode()` stub's own argument-to-return
 * edge, which Psalm specializes per call location for stubs it ships. A project that re-declares
 * `json_encode()` in its own stub loses that specialization and the strip would pool across sites.
 *
 * - HEX_TAG removes html. HEX_AMP is not needed: an entity cannot close a tag or an attribute.
 * - has_quotes needs BOTH HEX_QUOT and HEX_APOS, since either quote kind can end an attribute.
 * - Flags are read from the literal node type, AND-ed across a literal union so only bits set in
 *   every possible value count. Any non-literal atomic, unpack argument, or missing flags declines.
 * - The `flags` named argument is honoured; the encoded value must stay positional, because
 *   {@see NamedArgumentTaintHandler} strips a named value before this handler matters.
 * - A userland `json_encode()` in the current namespace declines. Qualified `Foo\json_encode()`
 *   declines. `use function json_encode as enc` is a known false negative of the written-name gate.
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

        $removed = 0;
        if (($flags & \JSON_HEX_TAG) !== 0) {
            $removed |= TaintKind::INPUT_HTML;
        }

        if (($flags & (\JSON_HEX_QUOT | \JSON_HEX_APOS)) === (\JSON_HEX_QUOT | \JSON_HEX_APOS)) {
            $removed |= TaintKind::INPUT_HAS_QUOTES;
        }

        return $removed;
    }

    /** Mirrors FunctionCallAnalyzer's function-id resolution: fully qualified, or unqualified with a namespace fallback. */
    private static function resolvesToCoreFunction(AddRemoveTaintsEvent $event, Name $name, StatementsAnalyzer $source): bool
    {
        if ($name instanceof Name\FullyQualified) {
            return true;
        }

        if (\count($name->getParts()) !== 1) {
            return false;
        }

        $functions = $event->getCodebase()->functions;
        $functionId = \strtolower($functions->getFullyQualifiedFunctionNameFromString($name->toString(), $source));

        return $functionId === 'json_encode' || !$functions->functionExists($source, $functionId);
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
