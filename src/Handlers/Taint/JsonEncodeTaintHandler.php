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
 * Treats `json_encode()` with literal `JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP` flags as a has_quotes
 * escape (Blade `@json` compiles to `json_encode($x, 15, 512)`). Core's `JsonEncodeTainter` removes only
 * html (with `JSON_HEX_TAG | JSON_HEX_AMP`) and keeps has_quotes by design, see vimeo/psalm#12453.
 *
 * - Without HEX_APOS a `'` stays raw, without HEX_QUOT a `"` becomes `\"` (a backslash escapes nothing in
 *   HTML), without HEX_AMP an attribute decodes a payload `&quot;` back to `"`.
 * - Known gap (the reason core keeps has_quotes): the encoder's own `"` delimiters still close a
 *   double-quoted attribute (`data-x="@json($v)"`). Accepted because such markup is visibly broken for
 *   every string, array or object value, so it rarely survives manual testing (ints, bools and null
 *   encode bare and render fine). Single-quoted attributes and `<script>` are safe with these flags.
 * - Flags are the literal node type, AND-ed across a literal union. Anything else declines.
 * - Only the written name `json_encode` is matched, so `use function json_encode as enc; enc($v, 15)`
 *   keeps its findings (accepted false positive).
 * - The removal lands on the stub's own argument-to-return edge, which is per call only while Psalm
 *   specializes `json_encode()` per call site. A project stub re-declaring it without specialization
 *   shares that edge between all calls, so the handler declines there.
 * - The handler answers only while the call's type is still unset (the `FunctionCallReturnTypeFetcher`
 *   dispatch that writes the edge), not on the later return/argument dispatches of the same node. Psalm
 *   before 7.0.0-rc9 applied a returned expression's removal to every return path of the enclosing
 *   function (vimeo/psalm#12470); since then it stays on that return's own edges. The gate is kept as a
 *   guard: if Psalm reorders dispatches, findings return and nothing is over-stripped.
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
        $quoteFlags = \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_HEX_AMP;
        if (($flags & $quoteFlags) !== $quoteFlags) {
            return 0;
        }

        // Same test as TaintFlowGraph::isCallSpecialized(). The core stub is always loaded, so storage exists.
        $storage = $codebase->functions->getStorage($source, 'json_encode');

        return $storage->specialize_call || $storage->builtin ? TaintKind::INPUT_HAS_QUOTES : 0;
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

    /**
     * Bits set in every possible literal value of the flags argument; 0 when any value is unknown.
     *
     * @psalm-capabilities read-props
     */
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
