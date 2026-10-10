<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Taint;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\Event\AddRemoveTaintsEvent;
use Psalm\Plugin\EventHandler\RemoveTaintsInterface;
use Psalm\Type\Atomic\TLiteralInt;
use Psalm\Type\TaintKind;
use Psalm\Type\Union;

/**
 * Treats `json_encode()` with literal HEX flags as escaping (Blade `@json` compiles to
 * `json_encode($x, 15, 512)`). Core does this for `htmlspecialchars()` flags in `HtmlFunctionTainter`,
 * but has no `json_encode()` branch. Delete this handler once it has one.
 *
 * - `JSON_HEX_TAG` removes html. `JSON_HEX_APOS` plus `JSON_HEX_QUOT` removes has_quotes; without
 *   HEX_APOS a `'` stays raw, without HEX_QUOT a `"` becomes `\"` (a backslash escapes nothing in HTML).
 * - Known gap: the encoder's own `"` delimiters still close a double-quoted attribute
 *   (`data-x="@json($v)"`). Accepted because such markup is visibly broken for every value, so it does
 *   not survive manual testing; single-quoted attributes and `<script>` are safe with these flags.
 * - Flags are the literal node type, AND-ed across a literal union. Anything else declines.
 * - The removal lands on the stub's own argument-to-return edge, which is per call only while Psalm
 *   specializes `json_encode()` per call site. A project stub re-declaring it without specialization
 *   shares that edge between all calls, so the handler declines there.
 * - `ReturnAnalyzer` applies the removal it gets for a returned expression to EVERY return path of the
 *   enclosing function. The handler therefore answers only while the call's type is still unset (the
 *   `FunctionCallReturnTypeFetcher` dispatch that writes the edge). If Psalm reorders that, findings
 *   return; nothing is over-stripped.
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
        ) {
            return 0;
        }

        // A first-class callable `json_encode(...)` never reaches here with its type unset.
        $source = $event->getStatementsSource();
        if (!$source instanceof StatementsAnalyzer || $source->node_data->getType($expr) instanceof Union) {
            return 0;
        }

        $codebase = $event->getCodebase();
        if (!self::resolvesToCoreFunction($codebase, $expr->name, $source)) {
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

        $flags = $flagsArg instanceof Arg ? self::provenFlagBits($source, $flagsArg) : 0;
        $quoteFlags = \JSON_HEX_APOS | \JSON_HEX_QUOT;
        $removed = (($flags & \JSON_HEX_TAG) !== 0 ? TaintKind::INPUT_HTML : 0)
            | (($flags & $quoteFlags) === $quoteFlags ? TaintKind::INPUT_HAS_QUOTES : 0);

        if ($removed === 0) {
            return 0;
        }

        // Same test as TaintFlowGraph::isCallSpecialized(). The core stub is always loaded, so storage exists.
        $storage = $codebase->functions->getStorage($source, 'json_encode');

        return $storage->specialize_call || $storage->builtin ? $removed : 0;
    }

    /** Mirrors FunctionCallAnalyzer: an unqualified, unaliased name falls back to the global function. */
    private static function resolvesToCoreFunction(Codebase $codebase, Name $name, StatementsAnalyzer $source): bool
    {
        // Psalm resolves `namespace\json_encode` as if unqualified (applying `use function`); PHP does not.
        if (!$name->isUnqualified()) {
            return $name->isFullyQualified() && $name->toLowerString() === 'json_encode';
        }

        $functions = $codebase->functions;
        $functionId = \strtolower($functions->getFullyQualifiedFunctionNameFromString($name->toString(), $source));

        return $functionId === 'json_encode'
            || ($functionId === \strtolower(($source->getNamespace() ?? '') . '\\json_encode')
                && !$functions->functionExists($source, $functionId));
    }

    /** Bits set in every possible literal value of the flags argument; 0 when any value is unknown. */
    private static function provenFlagBits(StatementsAnalyzer $source, Arg $flagsArg): int
    {
        $type = $source->node_data->getType($flagsArg->value);
        if (!$type instanceof Union) {
            return 0;
        }

        $flags = -1;
        foreach ($type->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TLiteralInt) {
                return 0;
            }

            $flags &= $atomic->value;
        }

        return $flags;
    }
}
